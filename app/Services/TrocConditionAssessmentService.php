<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class TrocConditionAssessmentService
{
    public function __construct(private NimbaAiAssistantService $aiAssistantService) {}

    public function analyzeStoredImage(string $absolutePath, string $publicUrl): array
    {
        $fallback = $this->fallbackAssessment($publicUrl, $absolutePath);

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return $fallback;
        }

        $binary = @file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return $fallback;
        }

        $mimeType = mime_content_type($absolutePath) ?: 'image/jpeg';
        $base64 = base64_encode($binary);

        $instruction = <<<'PROMPT'
Analyse cette photo de smartphone d'occasion. Réponds STRICTEMENT en JSON valide, sans markdown, sans texte avant ou après.

Schéma attendu:
{
  "overall_condition": "good|fair|poor",
  "confidence": 0.0,
  "detected_issues": {
    "screen_scratches": false,
    "screen_cracks": false,
    "back_glass_damage": false,
    "frame_dents": false,
    "camera_damage": false
  },
  "notes": ["..."],
  "recommended_questions": ["..."]
}

Contraintes:
- Ne déduis que ce qui est réellement visible.
- Si la photo est floue, mal cadrée, trop sombre ou partielle, dis-le dans notes et garde une confiance basse.
- recommended_questions doit contenir 2 à 4 questions courtes utiles pour affiner l'état du téléphone.
PROMPT;

        $analysis = $this->aiAssistantService->analyzeVision($instruction, [[
            'mime_type' => $mimeType,
            'base64' => $base64,
        ]], [
            'mode' => 'troc_vision',
        ]);

        if (!is_array($analysis)) {
            return $fallback;
        }

        if (trim((string) ($analysis['reply'] ?? '')) === '') {
            return $this->fallbackAssessment($publicUrl, $absolutePath, $analysis);
        }

        $parsed = $this->parseJsonReply((string) $analysis['reply']);
        if ($parsed === null) {
            Log::warning('troc.image_analysis_invalid_json', [
                'reply' => $analysis['reply'],
            ]);

            return $this->fallbackAssessment($publicUrl, $absolutePath, [
                'provider' => $analysis['provider'] ?? null,
                'model' => $analysis['model'] ?? null,
                'error' => 'invalid_json',
            ]);
        }

        return [
            'provider' => $analysis['provider'] ?? null,
            'model' => $analysis['model'] ?? null,
            'overall_condition' => $this->normalizeOverallCondition((string) ($parsed['overall_condition'] ?? 'fair')),
            'confidence' => $this->normalizeConfidence($parsed['confidence'] ?? 0.35),
            'detected_issues' => [
                'screen_scratches' => (bool) Arr::get($parsed, 'detected_issues.screen_scratches', false),
                'screen_cracks' => (bool) Arr::get($parsed, 'detected_issues.screen_cracks', false),
                'back_glass_damage' => (bool) Arr::get($parsed, 'detected_issues.back_glass_damage', false),
                'frame_dents' => (bool) Arr::get($parsed, 'detected_issues.frame_dents', false),
                'camera_damage' => (bool) Arr::get($parsed, 'detected_issues.camera_damage', false),
            ],
            'notes' => $this->normalizeStringList($parsed['notes'] ?? []),
            'recommended_questions' => $this->normalizeStringList($parsed['recommended_questions'] ?? []),
            'image_url' => $publicUrl,
            'source' => 'vision',
        ];
    }

    private function fallbackAssessment(string $publicUrl, ?string $absolutePath = null, array $failureContext = []): array
    {
        $notes = [
            'Analyse photo automatique indisponible ou peu fiable pour cette image.',
        ];

        $recommendedQuestions = [
            'L\'ecran a-t-il des rayures visibles ?',
            'Y a-t-il une fissure a l\'avant ou a l\'arriere ?',
            'La camera et Face ID fonctionnent-ils normalement ?',
        ];

        $reason = (string) ($failureContext['error'] ?? '');
        if ($reason === 'quota_exceeded') {
            $notes[] = 'Le provider IA a temporairement atteint sa limite de quota. Reessayez plus tard pour une analyse visuelle enrichie.';
        } elseif ($reason === 'provider_unavailable') {
            $notes[] = 'Le provider IA est temporairement indisponible. L estimation repose sur les informations manuelles.';
        } elseif ($reason === 'unauthorized') {
            $notes[] = 'La configuration du provider IA doit etre reverifiee pour relancer l analyse photo.';
        } elseif ($reason === 'invalid_json') {
            $notes[] = 'La reponse IA recue n etait pas exploitable automatiquement.';
        }

        if ($absolutePath !== null && is_file($absolutePath)) {
            $imageSize = @getimagesize($absolutePath);
            if (is_array($imageSize) && count($imageSize) >= 2) {
                [$width, $height] = $imageSize;

                if ($width < 700 || $height < 700) {
                    $notes[] = 'La photo semble de resolution limitee; un plan plus net et plus rapproche de l ecran aidera l estimation.';
                }

                if ($width > $height) {
                    $recommendedQuestions[] = 'Peux-tu envoyer une photo verticale de face de l ecran ?';
                }
            }
        }

        return [
            'provider' => $failureContext['provider'] ?? null,
            'model' => $failureContext['model'] ?? null,
            'overall_condition' => 'fair',
            'confidence' => 0.2,
            'detected_issues' => [
                'screen_scratches' => false,
                'screen_cracks' => false,
                'back_glass_damage' => false,
                'frame_dents' => false,
                'camera_damage' => false,
            ],
            'notes' => array_values(array_unique($notes)),
            'recommended_questions' => array_slice(array_values(array_unique($recommendedQuestions)), 0, 4),
            'image_url' => $publicUrl,
            'source' => 'fallback',
        ];
    }

    private function parseJsonReply(string $reply): ?array
    {
        $reply = trim($reply);
        $decoded = json_decode($reply, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $reply, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeOverallCondition(string $value): string
    {
        return match (strtolower(trim($value))) {
            'good', 'excellent' => 'good',
            'poor', 'bad' => 'poor',
            default => 'fair',
        };
    }

    private function normalizeConfidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.35;
        return max(0.0, min(1.0, $confidence));
    }

    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $item): string {
            return trim((string) $item);
        }, $value)));
    }
}