<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NimbaAiAssistantService
{
    public function __construct(
        private ChatbotKnowledgeBaseService $knowledgeBaseService,
        private NimbaWebSearchService $webSearchService,
    ) {}

    public function isEnabled(): bool
    {
        if (!(bool) config('services.nimba_ai.enabled', false)) {
            return false;
        }

        $defaultKey = trim((string) config('services.nimba_ai.api_key', ''));
        if ($defaultKey !== '') {
            return true;
        }

        $providers = config('services.nimba_ai.providers', []);
        if (!is_array($providers)) {
            return false;
        }

        foreach ($providers as $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }

            if (trim((string) ($providerConfig['api_key'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function answer(User $user, string $message, array $transcript = [], array $context = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $resolvedConfig = $this->resolveProviderConfig($context);
        $provider = $resolvedConfig['provider'];
        $baseUrl = $resolvedConfig['base_url'];
        $apiKey = $resolvedConfig['api_key'];
        $model = $resolvedConfig['model'];

        if (!$this->supportsProvider($provider) || $baseUrl === '' || $apiKey === '') {
            Log::warning('nimba_ai.invalid_provider_configuration', [
                'provider' => $provider,
                'base_url_configured' => $baseUrl !== '',
            ]);

            return null;
        }

        $retrievedKnowledge = $this->knowledgeBaseService->retrieveForMessage(
            $message,
            (string) ($context['channel'] ?? 'app'),
        );
        $webReferences = $this->webSearchService->searchIfRelevant($message, $context);

        $messages = [
            [
                'role' => 'system',
                'content' => (string) config('services.nimba_ai.system_prompt'),
            ],
            [
                'role' => 'system',
                'content' => sprintf(
                    'Utilisateur: %s. Role EdgPay: %s. Téléphone: %s. Canal: %s.',
                    $user->display_name ?? 'Client',
                    $user->role?->slug ?? 'unknown',
                    $user->phone ?? 'unknown',
                    $context['channel'] ?? 'app'
                ),
            ],
        ];

        $groundingContext = $this->buildGroundingContext($context, $retrievedKnowledge, $webReferences);
        if ($groundingContext !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $groundingContext,
            ];
        }

        foreach (array_slice($transcript, -6) as $item) {
            $role = ($item['role'] ?? 'assistant') === 'user' ? 'user' : 'assistant';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        try {
            $response = match ($provider) {
                'gemini', 'google', 'google-gemini' => $this->sendGeminiRequest($baseUrl, $apiKey, $messages, $resolvedConfig),
                'claude', 'anthropic' => $this->sendClaudeRequest($baseUrl, $apiKey, $messages, $resolvedConfig),
                default => $this->sendOpenAiCompatibleRequest($baseUrl, $apiKey, $messages, $resolvedConfig),
            };

            if (!$response->successful()) {
                Log::warning('nimba_ai.request_failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);
                return null;
            }

            $payload = $response->json();
            $content = $this->extractAssistantContent($provider, $payload);

            if ($content === '') {
                return null;
            }

            return [
                'reply' => $content,
                'provider' => $provider,
                'model' => $this->extractModelName($provider, $payload, $model),
                'finish_reason' => $this->extractFinishReason($provider, $payload),
                'knowledge_references' => array_map(fn (array $article): array => [
                    'key' => (string) ($article['key'] ?? ''),
                    'topic' => (string) ($article['topic'] ?? 'application'),
                    'source' => (string) ($article['source'] ?? 'rag'),
                    'score' => (float) ($article['score'] ?? 0.0),
                ], $retrievedKnowledge),
                'web_references' => array_map(fn (array $reference): array => [
                    'title' => (string) ($reference['title'] ?? ''),
                    'url' => (string) ($reference['url'] ?? ''),
                    'snippet' => (string) ($reference['snippet'] ?? ''),
                    'source' => (string) ($reference['source'] ?? 'web'),
                    'published_at' => (string) ($reference['published_at'] ?? ''),
                ], $webReferences),
                'web_search_provider' => $webReferences === [] ? null : $this->webSearchService->currentProvider(),
            ];
        } catch (\Throwable $error) {
            Log::warning('nimba_ai.exception', [
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }

    public function analyzeVision(string $instruction, array $images, array $context = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $resolvedConfig = $this->resolveProviderConfig($context);
        $provider = $resolvedConfig['provider'];
        $baseUrl = $resolvedConfig['base_url'];
        $apiKey = $resolvedConfig['api_key'];
        $model = $resolvedConfig['model'];

        if (!$this->supportsProvider($provider) || $baseUrl === '' || $apiKey === '' || $images === []) {
            return null;
        }

        try {
            $response = match ($provider) {
                'gemini', 'google', 'google-gemini' => $this->sendGeminiVisionRequest($baseUrl, $apiKey, $instruction, $images, $resolvedConfig),
                'claude', 'anthropic' => $this->sendClaudeVisionRequest($baseUrl, $apiKey, $instruction, $images, $resolvedConfig),
                default => $this->sendOpenAiCompatibleVisionRequest($baseUrl, $apiKey, $instruction, $images, $resolvedConfig),
            };

            if (!$response->successful()) {
                Log::warning('nimba_ai.vision_request_failed', [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ]);

                return null;
            }

            $payload = $response->json();
            $content = $this->extractAssistantContent($provider, $payload);

            if ($content === '') {
                return null;
            }

            return [
                'reply' => $content,
                'provider' => $provider,
                'model' => $this->extractModelName($provider, $payload, $model),
                'finish_reason' => $this->extractFinishReason($provider, $payload),
            ];
        } catch (\Throwable $error) {
            Log::warning('nimba_ai.vision_exception', [
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }

    private function supportsProvider(string $provider): bool
    {
        return in_array($provider, ['chatgpt', 'openai', 'gemini', 'google', 'google-gemini', 'claude', 'anthropic'], true);
    }

    public function adminPrimaryProviderStatus(): array
    {
        $provider = $this->resolvedPrimaryProvider();
        $enabled = (bool) config('services.nimba_ai.enabled', false);

        $providerConfigs = config('services.nimba_ai.providers', []);
        $providerConfig = is_array($providerConfigs) && is_array($providerConfigs[$provider] ?? null)
            ? $providerConfigs[$provider]
            : [];

        $baseUrl = $this->firstNonEmptyString(
            $providerConfig['base_url'] ?? null,
            config('services.nimba_ai.base_url', ''),
        );
        $apiKey = $this->firstNonEmptyString(
            $providerConfig['api_key'] ?? null,
            config('services.nimba_ai.api_key', ''),
        );

        $operational = $enabled && $baseUrl !== '' && $apiKey !== '' && $this->supportsProvider($provider);
        $status = 'ready';

        if (!$enabled) {
            $status = 'disabled';
        } elseif (!$this->supportsProvider($provider)) {
            $status = 'unsupported';
        } elseif ($apiKey === '') {
            $status = 'missing_api_key';
        } elseif ($baseUrl === '') {
            $status = 'missing_base_url';
        }

        return [
            'feature_enabled' => $enabled,
            'provider' => $provider,
            'api_key_configured' => $apiKey !== '',
            'base_url_configured' => $baseUrl !== '',
            'operational' => $operational,
            'status' => $status,
        ];
    }

    private function resolveProviderConfig(array $context): array
    {
        $agentProfile = is_array($context['agent_profile'] ?? null) ? $context['agent_profile'] : [];
        $agentProvider = trim((string) ($agentProfile['provider'] ?? ''));
        $provider = strtolower(trim((string) ($agentProvider !== '' ? $agentProvider : $this->resolvedPrimaryProvider())));
        $provider = $this->canonicalProvider($provider);

        $providerConfigs = config('services.nimba_ai.providers', []);
        $providerConfig = is_array($providerConfigs) && is_array($providerConfigs[$provider] ?? null)
            ? $providerConfigs[$provider]
            : [];

        $baseUrl = $this->firstNonEmptyString(
            $providerConfig['base_url'] ?? null,
            config('services.nimba_ai.base_url', ''),
        );
        $apiKey = $this->firstNonEmptyString(
            $providerConfig['api_key'] ?? null,
            config('services.nimba_ai.api_key', ''),
        );
        $agentModel = trim((string) ($agentProfile['model'] ?? ''));
        $model = $agentModel !== ''
            ? $agentModel
            : $this->firstNonEmptyString(
                $providerConfig['model'] ?? null,
                config('services.nimba_ai.model', 'gpt-4.1-mini'),
            );

        return [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $model,
            'organization' => $this->firstNonEmptyString(
                $providerConfig['organization'] ?? null,
                config('services.nimba_ai.organization', ''),
            ),
            'project' => $this->firstNonEmptyString(
                $providerConfig['project'] ?? null,
                config('services.nimba_ai.project', ''),
            ),
            'version' => $this->firstNonEmptyString(
                $providerConfig['version'] ?? null,
                '2023-06-01',
            ),
        ];
    }

    private function resolvedPrimaryProvider(): string
    {
        $override = SystemSetting::query()
            ->where('key', 'chatbot_primary_ai_provider')
            ->where('is_active', true)
            ->first();

        $provider = strtolower(trim((string) ($override?->formatted_value ?? $override?->value ?? config('services.nimba_ai.provider', 'chatgpt'))));
        $provider = $this->canonicalProvider($provider);

        return $this->supportsProvider($provider) ? $provider : 'chatgpt';
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

    private function canonicalProvider(string $provider): string
    {
        return match ($provider) {
            'openai' => 'chatgpt',
            'google', 'google-gemini' => 'gemini',
            'anthropic' => 'claude',
            default => $provider === '' ? 'chatgpt' : $provider,
        };
    }

    private function sendOpenAiCompatibleRequest(string $baseUrl, string $apiKey, array $messages, array $providerConfig = [])
    {
        $request = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.nimba_ai.timeout', 20));

        $organization = trim((string) ($providerConfig['organization'] ?? config('services.nimba_ai.organization', '')));
        if ($organization !== '') {
            $request = $request->withHeader('OpenAI-Organization', $organization);
        }

        $project = trim((string) ($providerConfig['project'] ?? config('services.nimba_ai.project', '')));
        if ($project !== '') {
            $request = $request->withHeader('OpenAI-Project', $project);
        }

        return $request->post($baseUrl, [
            'model' => (string) ($providerConfig['model'] ?? config('services.nimba_ai.model', 'gpt-4.1-mini')),
            'messages' => $messages,
            'temperature' => (float) config('services.nimba_ai.temperature', 0.3),
            'max_tokens' => (int) config('services.nimba_ai.max_tokens', 300),
        ]);
    }

    private function sendOpenAiCompatibleVisionRequest(
        string $baseUrl,
        string $apiKey,
        string $instruction,
        array $images,
        array $providerConfig = [],
    ) {
        $request = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.nimba_ai.timeout', 20));

        $organization = trim((string) ($providerConfig['organization'] ?? config('services.nimba_ai.organization', '')));
        if ($organization !== '') {
            $request = $request->withHeader('OpenAI-Organization', $organization);
        }

        $project = trim((string) ($providerConfig['project'] ?? config('services.nimba_ai.project', '')));
        if ($project !== '') {
            $request = $request->withHeader('OpenAI-Project', $project);
        }

        $content = [
            [
                'type' => 'text',
                'text' => $instruction,
            ],
        ];

        foreach ($images as $image) {
            $dataUrl = $this->buildDataUrl($image);
            if ($dataUrl === null) {
                continue;
            }

            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $dataUrl,
                ],
            ];
        }

        return $request->post($baseUrl, [
            'model' => (string) ($providerConfig['model'] ?? config('services.nimba_ai.model', 'gpt-4.1-mini')),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Tu es NIMBA Vision. Tu observes seulement ce qui est visible sur une photo de smartphone et tu réponds strictement au format demandé.',
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => (int) config('services.nimba_ai.max_tokens', 300),
        ]);
    }

    private function sendGeminiRequest(string $baseUrl, string $apiKey, array $messages, array $providerConfig)
    {
        $baseUrl = $this->resolveGeminiEndpoint(
            $baseUrl,
            (string) ($providerConfig['model'] ?? 'gemini-2.0-flash')
        );

        $systemInstruction = trim((string) ($messages[0]['content'] ?? ''));
        $chatContents = [];

        foreach (array_slice($messages, 1) as $item) {
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $role = ($item['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $chatContents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $content],
                ],
            ];
        }

        return Http::acceptJson()
            ->withQueryParameters(['key' => $apiKey])
            ->timeout((int) config('services.nimba_ai.timeout', 20))
            ->post($baseUrl, [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemInstruction],
                    ],
                ],
                'contents' => $chatContents,
                'generationConfig' => [
                    'temperature' => (float) config('services.nimba_ai.temperature', 0.3),
                    'maxOutputTokens' => (int) config('services.nimba_ai.max_tokens', 300),
                ],
            ]);
    }

    private function sendGeminiVisionRequest(
        string $baseUrl,
        string $apiKey,
        string $instruction,
        array $images,
        array $providerConfig,
    ) {
        $baseUrl = $this->resolveGeminiEndpoint(
            $baseUrl,
            (string) ($providerConfig['model'] ?? 'gemini-2.0-flash')
        );

        $parts = [
            ['text' => $instruction],
        ];

        foreach ($images as $image) {
            $mimeType = trim((string) ($image['mime_type'] ?? 'image/jpeg'));
            $base64 = trim((string) ($image['base64'] ?? ''));
            if ($base64 === '') {
                continue;
            }

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data' => $base64,
                ],
            ];
        }

        return Http::acceptJson()
            ->withQueryParameters(['key' => $apiKey])
            ->timeout((int) config('services.nimba_ai.timeout', 20))
            ->post($baseUrl, [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => 'Tu es NIMBA Vision. Tu observes seulement ce qui est visible sur une photo de smartphone et tu réponds strictement au format demandé.'],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => (int) config('services.nimba_ai.max_tokens', 300),
                ],
            ]);
    }

    private function resolveGeminiEndpoint(string $baseUrl, string $model): string
    {
        if ($model === '') {
            return $baseUrl;
        }

        if (str_contains($baseUrl, '{model}')) {
            return str_replace('{model}', $model, $baseUrl);
        }

        return preg_replace(
            '/\/models\/[^:]+:generateContent$/',
            '/models/' . $model . ':generateContent',
            $baseUrl,
        ) ?: $baseUrl;
    }

    private function sendClaudeRequest(string $baseUrl, string $apiKey, array $messages, array $providerConfig)
    {
        $systemPrompt = collect($messages)
            ->filter(fn (array $message): bool => ($message['role'] ?? '') === 'system')
            ->map(fn (array $message): string => trim((string) ($message['content'] ?? '')))
            ->filter()
            ->implode("\n\n");

        $claudeMessages = collect($messages)
            ->reject(fn (array $message): bool => ($message['role'] ?? '') === 'system')
            ->map(function (array $message): array {
                $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';

                return [
                    'role' => $role,
                    'content' => trim((string) ($message['content'] ?? '')),
                ];
            })
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();

        return Http::acceptJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) ($providerConfig['version'] ?? '2023-06-01'),
            ])
            ->timeout((int) config('services.nimba_ai.timeout', 20))
            ->post($baseUrl, [
                'model' => (string) ($providerConfig['model'] ?? 'claude-3-5-sonnet-20241022'),
                'max_tokens' => (int) config('services.nimba_ai.max_tokens', 300),
                'temperature' => (float) config('services.nimba_ai.temperature', 0.3),
                'system' => $systemPrompt,
                'messages' => $claudeMessages,
            ]);
    }

    private function sendClaudeVisionRequest(
        string $baseUrl,
        string $apiKey,
        string $instruction,
        array $images,
        array $providerConfig,
    ) {
        $content = [
            [
                'type' => 'text',
                'text' => $instruction,
            ],
        ];

        foreach ($images as $image) {
            $mimeType = trim((string) ($image['mime_type'] ?? 'image/jpeg'));
            $base64 = trim((string) ($image['base64'] ?? ''));
            if ($base64 === '') {
                continue;
            }

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64,
                ],
            ];
        }

        return Http::acceptJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => (string) ($providerConfig['version'] ?? '2023-06-01'),
            ])
            ->timeout((int) config('services.nimba_ai.timeout', 20))
            ->post($baseUrl, [
                'model' => (string) ($providerConfig['model'] ?? 'claude-3-5-sonnet-20241022'),
                'max_tokens' => (int) config('services.nimba_ai.max_tokens', 300),
                'temperature' => 0.1,
                'system' => 'Tu es NIMBA Vision. Tu observes seulement ce qui est visible sur une photo de smartphone et tu réponds strictement au format demandé.',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $content,
                    ],
                ],
            ]);
    }

    private function buildDataUrl(array $image): ?string
    {
        $mimeType = trim((string) ($image['mime_type'] ?? 'image/jpeg'));
        $base64 = trim((string) ($image['base64'] ?? ''));

        if ($base64 === '') {
            return null;
        }

        return 'data:' . $mimeType . ';base64,' . $base64;
    }

    private function buildGroundingContext(array $context, array $retrievedKnowledge = [], array $webReferences = []): string
    {
        $knowledgeEntries = config('chatbot.app_knowledge', []);
        $knowledgeDigest = [];

        if (is_array($knowledgeEntries)) {
            foreach ($knowledgeEntries as $key => $entry) {
                $reply = trim((string) Arr::get($entry, 'reply', ''));
                if ($reply === '') {
                    continue;
                }

                $knowledgeDigest[] = sprintf(
                    '- %s (%s): %s',
                    $key,
                    Arr::get($entry, 'knowledge_topic', 'application'),
                    $this->truncateSentence($reply, 220)
                );
            }
        }

        $lines = [
            'Contrainte produit: tu es le provider IA principal de NIMBA pour EdgPay.',
            'Priorité: répondre d abord avec les informations EdgPay confirmées ci-dessous, puis avec culture générale seulement si la question est générale.',
            'Si une donnée métier EdgPay n est pas confirmée, dis explicitement que tu n as pas la donnée vérifiée au lieu d inventer.',
            'Capacités confirmées: consultation de solde, transfert, historique, dépôt, retrait, support, sécurité, paiement EDG prépayé et postpayé.',
        ];

        if (!empty($context['memory_summary']) && is_array($context['memory_summary'])) {
            $lines[] = 'Contexte utilisateur utile: ' . implode(' | ', array_filter($context['memory_summary'], 'is_string'));
        }

        if (!empty($context['mode'])) {
            $lines[] = 'Mode de réponse: ' . (string) $context['mode'] . '.';
        }

        if (!empty($context['agent_profile']) && is_array($context['agent_profile'])) {
            $agentLabel = (string) Arr::get($context, 'agent_profile.label', 'NIMBA Classique');
            $agentDescription = (string) Arr::get($context, 'agent_profile.description', '');
            $agentPrompt = (string) Arr::get($context, 'agent_profile.system_prompt', '');
            $agentProvider = trim((string) Arr::get($context, 'agent_profile.provider', ''));
            $agentModel = trim((string) Arr::get($context, 'agent_profile.model', ''));
            $lines[] = 'Agent conversationnel choisi: ' . $agentLabel . '.';
            if ($agentDescription !== '') {
                $lines[] = 'Description agent: ' . $agentDescription;
            }
            if ($agentProvider !== '') {
                $lines[] = 'Provider réel agent: ' . $agentProvider . '.';
            }
            if ($agentModel !== '') {
                $lines[] = 'Modèle réel agent: ' . $agentModel . '.';
            }
            if ($agentPrompt !== '') {
                $lines[] = 'Consigne agent: ' . $agentPrompt;
            }
        }

        if (!empty($knowledgeDigest)) {
            $lines[] = 'Connaissances EdgPay vérifiées:';
            $lines[] = implode("\n", array_slice($knowledgeDigest, 0, 12));
        }

        if (!empty($retrievedKnowledge)) {
            $lines[] = 'Extraits RAG prioritaires pour cette question:';
            $lines[] = implode("\n", array_map(function (array $article): string {
                return sprintf(
                    '- [%s] %s: %s',
                    $article['topic'] ?? 'application',
                    $article['title'] ?? ($article['key'] ?? 'article'),
                    $this->truncateSentence((string) ($article['content'] ?? ''), 260)
                );
            }, $retrievedKnowledge));
        }

        if (!empty($webReferences)) {
            $lines[] = 'Sources web recentes a utiliser seulement pour les questions d actualite ou de contexte externe:';
            $lines[] = 'Si tu t appuies sur ces sources, signale dans la reponse qu il s agit d informations web recentes, cite la source la plus utile et reste prudent si les sources divergent.';
            $lines[] = implode("\n", array_map(function (array $reference): string {
                return sprintf(
                    '- [%s] %s%s: %s',
                    $reference['source'] ?? 'web',
                    $reference['title'] ?? 'source',
                    ($reference['published_at'] ?? '') !== '' ? ' (' . $reference['published_at'] . ')' : '',
                    $this->truncateSentence((string) ($reference['snippet'] ?? ''), 260)
                );
            }, $webReferences));
        }

        return implode("\n", $lines);
    }

    private function truncateSentence(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 3, 'UTF-8')) . '...';
    }

    private function extractAssistantContent(string $provider, mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        if (in_array($provider, ['gemini', 'google', 'google-gemini'], true)) {
            $parts = Arr::get($payload, 'candidates.0.content.parts', []);
            if (!is_array($parts)) {
                return '';
            }

            $texts = collect($parts)
                ->map(fn (mixed $part): string => is_array($part) ? trim((string) ($part['text'] ?? '')) : '')
                ->filter()
                ->values()
                ->all();

            return trim(implode("\n", $texts));
        }

        if (in_array($provider, ['claude', 'anthropic'], true)) {
            $parts = Arr::get($payload, 'content', []);
            if (!is_array($parts)) {
                return '';
            }

            $texts = collect($parts)
                ->map(function (mixed $part): string {
                    if (!is_array($part)) {
                        return '';
                    }

                    if (($part['type'] ?? '') !== 'text') {
                        return '';
                    }

                    return trim((string) ($part['text'] ?? ''));
                })
                ->filter()
                ->values()
                ->all();

            return trim(implode("\n", $texts));
        }

        return trim((string) Arr::get($payload, 'choices.0.message.content', ''));
    }

    private function extractModelName(string $provider, mixed $payload, string $fallbackModel): string
    {
        $defaultModel = $fallbackModel !== ''
            ? $fallbackModel
            : (string) config('services.nimba_ai.model', 'gpt-4.1-mini');

        if (!is_array($payload)) {
            return $defaultModel;
        }

        if (in_array($provider, ['gemini', 'google', 'google-gemini'], true)) {
            return (string) ($payload['modelVersion'] ?? $defaultModel);
        }

        if (in_array($provider, ['claude', 'anthropic'], true)) {
            return (string) ($payload['model'] ?? $defaultModel);
        }

        return (string) Arr::get($payload, 'model', $defaultModel);
    }

    private function extractFinishReason(string $provider, mixed $payload): string
    {
        if (!is_array($payload)) {
            return 'stop';
        }

        if (in_array($provider, ['gemini', 'google', 'google-gemini'], true)) {
            return strtolower((string) Arr::get($payload, 'candidates.0.finishReason', 'stop'));
        }

        if (in_array($provider, ['claude', 'anthropic'], true)) {
            return strtolower((string) ($payload['stop_reason'] ?? 'stop'));
        }

        return (string) Arr::get($payload, 'choices.0.finish_reason', 'stop');
    }
}