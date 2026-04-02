<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AdminDocumentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDocumentProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        $profile = AdminDocumentProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['payload' => $this->defaultPayload()],
        );

        return response()->json([
            'success' => true,
            'data' => $this->normalizedPayload($profile),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        $validated = $request->validate([
            'clients' => ['sometimes', 'array'],
            'clients.*.nom' => ['required', 'string', 'max:255'],
            'clients.*.phone' => ['nullable', 'string', 'max:50'],
            'clients.*.address' => ['nullable', 'string', 'max:500'],
            'clients.*.email' => ['nullable', 'email', 'max:255'],
            'beneficiaires' => ['sometimes', 'array'],
            'beneficiaires.*.nom' => ['required', 'string', 'max:255'],
            'beneficiaires.*.phone' => ['nullable', 'string', 'max:50'],
            'beneficiaires.*.address' => ['nullable', 'string', 'max:500'],
            'beneficiaires.*.email' => ['nullable', 'email', 'max:255'],
            'payment_beneficiaires' => ['sometimes', 'array'],
            'payment_beneficiaires.*.nom' => ['required', 'string', 'max:255'],
            'payment_beneficiaires.*.phone' => ['nullable', 'string', 'max:50'],
            'payment_beneficiaires.*.address' => ['nullable', 'string', 'max:500'],
            'payment_beneficiaires.*.email' => ['nullable', 'email', 'max:255'],
            'custom_condition_templates' => ['sometimes', 'array'],
            'custom_condition_templates.*.key' => ['required', 'string', 'max:120'],
            'custom_condition_templates.*.label' => ['required', 'string', 'max:255'],
            'custom_condition_templates.*.content' => ['required', 'string', 'max:5000'],
            'show_real_pdf_preview' => ['sometimes', 'boolean'],
            'cachet_name' => ['nullable', 'string', 'max:255'],
            'cachet_b64' => ['nullable', 'string'],
        ]);

        if (array_key_exists('cachet_b64', $validated)) {
            $cachet = trim((string) ($validated['cachet_b64'] ?? ''));
            if ($cachet !== '' && base64_decode($cachet, true) === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le cachet encodé est invalide.',
                    'errors' => [
                        'cachet_b64' => ['Le cachet doit être un base64 valide.'],
                    ],
                ], 422);
            }
        }

        $profile = AdminDocumentProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['payload' => $this->defaultPayload()],
        );

        $payload = $this->normalizedPayload($profile);

        if (array_key_exists('clients', $validated)) {
            $payload['clients'] = $this->sanitizeParties($validated['clients']);
        }

        if (array_key_exists('beneficiaires', $validated)) {
            $payload['beneficiaires'] = $this->sanitizeParties($validated['beneficiaires']);
        }

        if (array_key_exists('payment_beneficiaires', $validated)) {
            $payload['payment_beneficiaires'] = $this->sanitizeParties($validated['payment_beneficiaires']);
        }

        if (array_key_exists('custom_condition_templates', $validated)) {
            $payload['custom_condition_templates'] = $this->sanitizeTemplates($validated['custom_condition_templates']);
        }

        if (array_key_exists('show_real_pdf_preview', $validated)) {
            $payload['show_real_pdf_preview'] = (bool) $validated['show_real_pdf_preview'];
        }

        if (array_key_exists('cachet_name', $validated)) {
            $payload['cachet_name'] = $this->cleanNullableString($validated['cachet_name'] ?? null);
        }

        if (array_key_exists('cachet_b64', $validated)) {
            $payload['cachet_b64'] = $this->cleanNullableString($validated['cachet_b64'] ?? null);
            if ($payload['cachet_b64'] === null) {
                $payload['cachet_name'] = null;
            }
        }

        $profile->payload = $payload;
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil documentaire mis à jour.',
            'data' => $this->normalizedPayload($profile->fresh()),
        ]);
    }

    private function defaultPayload(): array
    {
        return [
            'clients' => [],
            'beneficiaires' => [],
            'payment_beneficiaires' => [],
            'custom_condition_templates' => [],
            'show_real_pdf_preview' => false,
            'cachet_name' => null,
            'cachet_b64' => null,
        ];
    }

    private function normalizedPayload(AdminDocumentProfile $profile): array
    {
        $payload = is_array($profile->payload) ? $profile->payload : [];

        return [
            ...$this->defaultPayload(),
            ...$payload,
        ];
    }

    private function sanitizeParties(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            $nom = trim((string) ($item['nom'] ?? ''));
            if ($nom === '') {
                return null;
            }

            return [
                'nom' => $nom,
                'phone' => trim((string) ($item['phone'] ?? '')),
                'address' => trim((string) ($item['address'] ?? '')),
                'email' => trim((string) ($item['email'] ?? '')),
            ];
        }, $items)));
    }

    private function sanitizeTemplates(array $items): array
    {
        return array_values(array_filter(array_map(function (array $item): ?array {
            $key = trim((string) ($item['key'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));

            if ($key === '' || $label === '' || $content === '') {
                return null;
            }

            return [
                'key' => $key,
                'label' => $label,
                'content' => $content,
            ];
        }, $items)));
    }

    private function cleanNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }
}