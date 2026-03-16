<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NimbaWebSearchService
{
    private const SUPPORTED_PROVIDERS = ['serper', 'tavily'];

    public function currentProvider(): string
    {
        return $this->provider();
    }

    public function adminStatus(): array
    {
        $provider = $this->provider();
        $baseUrl = $this->resolvedBaseUrl($provider);
        $apiKey = $this->resolvedApiKey($provider);
        $featureEnabled = $this->resolvedEnabled();
        $baseUrlConfigured = $baseUrl !== '';
        $apiKeyConfigured = $apiKey !== '';
        $operational = $featureEnabled && $baseUrlConfigured && $apiKeyConfigured;

        $status = 'ready';
        if (!$featureEnabled) {
            $status = 'disabled';
        } elseif (!$apiKeyConfigured) {
            $status = 'missing_api_key';
        } elseif (!$baseUrlConfigured) {
            $status = 'missing_base_url';
        }

        return [
            'feature_enabled' => $featureEnabled,
            'provider' => $provider,
            'api_key_configured' => $apiKeyConfigured,
            'base_url_configured' => $baseUrlConfigured,
            'operational' => $operational,
            'status' => $status,
        ];
    }

    public function isEnabled(): bool
    {
        if (!$this->resolvedEnabled()) {
            return false;
        }

        $provider = $this->provider();
        $baseUrl = $this->resolvedBaseUrl($provider);
        $apiKey = $this->resolvedApiKey($provider);

        if ($provider === '' || $baseUrl === '') {
            return false;
        }

        if (in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return $apiKey !== '';
        }

        return false;
    }

    private function resolvedEnabled(): bool
    {
        $override = SystemSetting::query()
            ->where('key', 'chatbot_web_search_enabled')
            ->where('is_active', true)
            ->first();

        if ($override !== null) {
            $rawValue = $override->formatted_value ?? $override->value;

            return $this->parseBooleanValue($rawValue);
        }

        return (bool) config('services.nimba_ai.web_search.enabled', false);
    }

    public function searchIfRelevant(string $message, array $context = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        if (!$this->shouldSearch($message, $context)) {
            return [];
        }

        try {
            return $this->search($message);
        } catch (\Throwable $error) {
            Log::warning('nimba_ai.web_search_exception', [
                'provider' => $this->provider(),
                'error' => $error->getMessage(),
            ]);

            return [];
        }
    }

    public function shouldSearch(string $message, array $context = []): bool
    {
        if (($context['force_web_search'] ?? false) === true) {
            return true;
        }

        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, 'edgpay') || str_contains($normalized, 'nimba')) {
            return false;
        }

        $currentMarkers = [
            'actualite',
            'actuellement',
            'actuelle',
            'en ce moment',
            'aujourd hui',
            'maintenant',
            'recemment',
            'dernieres nouvelles',
            'derniere nouvelle',
            'latest',
            'breaking',
            'news',
            'situation en',
            'que se passe',
            'qu est ce qui se passe',
            'quels sont les derniers',
            'quelles sont les dernieres',
        ];

        foreach ($currentMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        $topics = [
            'iran',
            'ukraine',
            'gaza',
            'israel',
            'palestine',
            'soudan',
            'election',
            'elections',
            'guerre',
            'conflit',
            'crise',
            'sanctions',
            'inflation',
            'bitcoin',
            'petrole',
            'dollar',
            'meteo',
            'bourse',
            'covid',
            'ebola',
        ];

        $questionSignals = [
            'quelle est la situation',
            'quelle est la tendance',
            'quel est le cours',
            'quoi de neuf',
            'que disent les nouvelles',
        ];

        foreach ($questionSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                foreach ($topics as $topic) {
                    if (str_contains($normalized, $topic)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function search(string $message): array
    {
        return match ($this->provider()) {
            'tavily' => $this->searchWithTavily($message),
            default => $this->searchWithSerper($message),
        };
    }

    private function provider(): string
    {
        $override = SystemSetting::query()
            ->where('key', 'chatbot_web_search_provider')
            ->where('is_active', true)
            ->first();

        $provider = strtolower(trim((string) ($override?->formatted_value ?? $override?->value ?? config('services.nimba_ai.web_search.provider', 'serper'))));

        return in_array($provider, self::SUPPORTED_PROVIDERS, true) ? $provider : 'serper';
    }

    private function resolvedBaseUrl(string $provider): string
    {
        return $this->firstNonEmptyString(
            config("services.nimba_ai.web_search.providers.{$provider}.base_url"),
            config('services.nimba_ai.web_search.base_url', ''),
        );
    }

    private function resolvedApiKey(string $provider): string
    {
        return $this->firstNonEmptyString(
            config("services.nimba_ai.web_search.providers.{$provider}.api_key"),
            config('services.nimba_ai.web_search.api_key', ''),
        );
    }

    private function parseBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) ($value ?? '')));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function searchWithSerper(string $message): array
    {
        $response = Http::acceptJson()
            ->withHeader('X-API-KEY', $this->resolvedApiKey('serper'))
            ->timeout((int) config('services.nimba_ai.web_search.timeout', 12))
            ->post($this->resolvedBaseUrl('serper'), [
                'q' => $message,
                'gl' => (string) config('services.nimba_ai.web_search.region', 'gn'),
                'hl' => (string) config('services.nimba_ai.web_search.language', 'fr'),
                'num' => (int) config('services.nimba_ai.web_search.max_results', 4),
            ]);

        if (!$response->successful()) {
            Log::warning('nimba_ai.web_search_failed', [
                'provider' => 'serper',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [];
        }

        $organic = Arr::get($response->json(), 'organic', []);
        if (!is_array($organic)) {
            return [];
        }

        return collect($organic)
            ->map(function (mixed $item): array {
                if (!is_array($item)) {
                    return [];
                }

                return [
                    'title' => trim((string) ($item['title'] ?? '')),
                    'url' => trim((string) ($item['link'] ?? '')),
                    'snippet' => trim((string) ($item['snippet'] ?? '')),
                    'source' => trim((string) ($item['source'] ?? parse_url((string) ($item['link'] ?? ''), PHP_URL_HOST) ?? 'web')),
                    'published_at' => trim((string) ($item['date'] ?? '')),
                ];
            })
            ->filter(fn (array $item): bool => ($item['title'] ?? '') !== '' && ($item['snippet'] ?? '') !== '')
            ->take((int) config('services.nimba_ai.web_search.max_results', 4))
            ->values()
            ->all();
    }

    private function searchWithTavily(string $message): array
    {
        $response = Http::acceptJson()
            ->timeout((int) config('services.nimba_ai.web_search.timeout', 12))
            ->post($this->resolvedBaseUrl('tavily'), [
                'api_key' => $this->resolvedApiKey('tavily'),
                'query' => $message,
                'search_depth' => 'advanced',
                'max_results' => (int) config('services.nimba_ai.web_search.max_results', 4),
                'topic' => 'news',
            ]);

        if (!$response->successful()) {
            Log::warning('nimba_ai.web_search_failed', [
                'provider' => 'tavily',
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [];
        }

        $results = Arr::get($response->json(), 'results', []);
        if (!is_array($results)) {
            return [];
        }

        return collect($results)
            ->map(function (mixed $item): array {
                if (!is_array($item)) {
                    return [];
                }

                return [
                    'title' => trim((string) ($item['title'] ?? '')),
                    'url' => trim((string) ($item['url'] ?? '')),
                    'snippet' => trim((string) ($item['content'] ?? '')),
                    'source' => trim((string) (parse_url((string) ($item['url'] ?? ''), PHP_URL_HOST) ?? 'web')),
                    'published_at' => trim((string) ($item['published_date'] ?? '')),
                ];
            })
            ->filter(fn (array $item): bool => ($item['title'] ?? '') !== '' && ($item['snippet'] ?? '') !== '')
            ->take((int) config('services.nimba_ai.web_search.max_results', 4))
            ->values()
            ->all();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(['’', '\'', 'œ'], [' ', ' ', 'oe'], $value);
        $value = preg_replace('/[^\pL\pN\s]+/u', ' ', $value) ?? '';
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $stringValue = trim((string) ($value ?? ''));
            if ($stringValue !== '') {
                return $stringValue;
            }
        }

        return '';
    }
}