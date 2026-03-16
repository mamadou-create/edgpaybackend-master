<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;

class ChatbotKnowledgeBaseService
{
    public function retrieveForMessage(string $message, ?string $channel = null, ?int $limit = null): array
    {
        if (!$this->isRagEnabled()) {
            return [];
        }

        $normalizedMessage = $this->normalize($message);
        if ($normalizedMessage === '') {
            return [];
        }

        $articles = $this->loadKnowledgeArticles($channel);
        $scored = [];

        foreach ($articles as $article) {
            $score = $this->scoreArticle($normalizedMessage, $article);
            if ($score < $this->minimumScore()) {
                continue;
            }

            $article['score'] = round($score, 4);
            $scored[] = $article;
        }

        usort($scored, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $max = $limit ?? $this->maxSnippets();

        return array_slice($scored, 0, max(1, $max));
    }

    private function loadKnowledgeArticles(?string $channel): array
    {
        return array_merge(
            $this->loadConfiguredKnowledge($channel),
            $this->loadCustomKnowledge($channel),
        );
    }

    private function loadConfiguredKnowledge(?string $channel): array
    {
        $entries = config('chatbot.app_knowledge', []);
        if (!is_array($entries)) {
            return [];
        }

        $articles = [];
        foreach ($entries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $override = $this->getAppKnowledgeOverride((string) $key);
            if (is_array($override)) {
                $entry = array_merge($entry, $override);
            }

            $article = [
                'key' => (string) $key,
                'title' => (string) ($entry['title'] ?? str_replace('_', ' ', (string) $key)),
                'topic' => (string) ($entry['knowledge_topic'] ?? 'application'),
                'content' => (string) ($entry['reply'] ?? ''),
                'patterns' => $this->normalizeList($entry['patterns'] ?? []),
                'keywords' => $this->normalizeList($entry['keywords'] ?? []),
                'channels' => ['app', 'whatsapp'],
                'source' => 'app_knowledge',
                'priority' => (float) ($entry['priority'] ?? 0.5),
            ];

            if ($article['content'] === '') {
                continue;
            }

            if (!$this->supportsChannel($article, $channel)) {
                continue;
            }

            $articles[] = $article;
        }

        return $articles;
    }

    private function loadCustomKnowledge(?string $channel): array
    {
        return SystemSetting::query()
            ->where('is_active', true)
            ->where('key', 'like', 'chatbot_knowledge_article_%')
            ->orderBy('order')
            ->get()
            ->map(function (SystemSetting $setting) use ($channel): ?array {
                $value = $setting->formatted_value;
                if (!is_array($value)) {
                    $decoded = json_decode((string) $setting->value, true);
                    $value = is_array($decoded) ? $decoded : null;
                }

                if (!is_array($value)) {
                    return null;
                }

                $article = [
                    'key' => (string) ($value['key'] ?? str_replace('chatbot_knowledge_article_', '', (string) $setting->key)),
                    'title' => (string) ($value['title'] ?? str_replace('chatbot_knowledge_article_', '', (string) $setting->key)),
                    'topic' => (string) ($value['topic'] ?? 'application'),
                    'content' => (string) ($value['content'] ?? $value['reply'] ?? ''),
                    'patterns' => $this->normalizeList($value['patterns'] ?? []),
                    'keywords' => $this->normalizeList($value['keywords'] ?? []),
                    'channels' => $this->normalizeList($value['channels'] ?? ['app', 'whatsapp']),
                    'source' => 'system_setting',
                    'priority' => (float) ($value['priority'] ?? 0.7),
                ];

                if ($article['content'] === '' || !$this->supportsChannel($article, $channel)) {
                    return null;
                }

                return $article;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function scoreArticle(string $normalizedMessage, array $article): float
    {
        $score = 0.0;

        if ($this->matchesAny($normalizedMessage, $article['patterns'] ?? [])) {
            $score += 0.7;
        }

        $messageTokens = $this->tokens($normalizedMessage);
        $articleTokens = array_unique(array_merge(
            $this->tokens((string) ($article['title'] ?? '')),
            $this->tokens((string) ($article['topic'] ?? '')),
            $this->tokens((string) ($article['content'] ?? '')),
            $this->tokens(implode(' ', $article['patterns'] ?? [])),
            $this->tokens(implode(' ', $article['keywords'] ?? [])),
        ));

        if (!empty($messageTokens) && !empty($articleTokens)) {
            $overlap = count(array_intersect($messageTokens, $articleTokens));
            $tokenRatio = $overlap / max(1, min(count($messageTokens), 8));
            $score += min(0.45, $tokenRatio * 0.45);
        }

        $score += min(0.2, max(0.0, ((float) ($article['priority'] ?? 0.0)) * 0.1));

        return min(1.0, $score);
    }

    private function getAppKnowledgeOverride(string $key): ?array
    {
        $setting = SystemSetting::query()
            ->where('key', "chatbot_app_knowledge_{$key}")
            ->where('is_active', true)
            ->first();

        if (!$setting) {
            return null;
        }

        $value = $setting->formatted_value;
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $setting->value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isRagEnabled(): bool
    {
        $setting = SystemSetting::query()->where('key', 'nimba_ai_rag_enabled')->where('is_active', true)->first();
        if ($setting) {
            $raw = $setting->formatted_value;
            if (is_bool($raw)) {
                return $raw;
            }

            return filter_var((string) $setting->value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('services.nimba_ai.rag_enabled', true);
    }

    private function maxSnippets(): int
    {
        $setting = SystemSetting::query()->where('key', 'nimba_ai_rag_max_snippets')->where('is_active', true)->first();
        if ($setting) {
            return max(1, (int) ($setting->formatted_value ?? $setting->value ?? 4));
        }

        return max(1, (int) config('services.nimba_ai.rag_max_snippets', 4));
    }

    private function minimumScore(): float
    {
        $setting = SystemSetting::query()->where('key', 'nimba_ai_rag_min_score')->where('is_active', true)->first();
        if ($setting) {
            return max(0.01, (float) ($setting->formatted_value ?? $setting->value ?? 0.18));
        }

        return max(0.01, (float) config('services.nimba_ai.rag_min_score', 0.18));
    }

    private function supportsChannel(array $article, ?string $channel): bool
    {
        if ($channel === null || $channel === '') {
            return true;
        }

        $channels = $article['channels'] ?? [];
        if (!is_array($channels) || empty($channels)) {
            return true;
        }

        return in_array($this->normalize($channel), $channels, true);
    }

    private function normalizeList(array $values): array
    {
        return array_values(array_filter(array_map(function ($value): string {
            return is_string($value) ? $this->normalize($value) : '';
        }, $values)));
    }

    private function tokens(string $value): array
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(array_unique(explode(' ', $normalized)), fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 4));
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['é', 'è', 'ê', 'ë', 'à', 'â', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ç'], ['e', 'e', 'e', 'e', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'c'], $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}