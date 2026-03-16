<?php
// app/Http\Controllers\API\SystemSettingController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NimbaAiAssistantService;
use App\Services\NimbaWebSearchService;
use App\Interfaces\SystemSettingRepositoryInterface;
use App\Classes\ApiResponseClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SystemSettingController extends Controller
{
    private const SUPPORTED_CHATBOT_PROVIDERS = ['chatgpt', 'gemini', 'claude'];
    private const SUPPORTED_WEB_SEARCH_PROVIDERS = ['serper', 'tavily'];

    private SystemSettingRepositoryInterface $systemSettingRepository;
    private NimbaWebSearchService $webSearchService;
    private NimbaAiAssistantService $aiAssistantService;

    public function __construct(SystemSettingRepositoryInterface $systemSettingRepository, NimbaWebSearchService $webSearchService, NimbaAiAssistantService $aiAssistantService)
    {
        $this->systemSettingRepository = $systemSettingRepository;
        $this->webSearchService = $webSearchService;
        $this->aiAssistantService = $aiAssistantService;
    }

    private function serializeSetting(object $setting, array $runtimeStatuses = []): array
    {
        $runtimeStatus = $runtimeStatuses[$setting->key] ?? null;

        $payload = [
            'id' => $setting->id,
            'key' => $setting->key,
            'value' => (string) ($setting->value ?? ''),
            'formatted_value' => $setting->formatted_value ?? null,
            'type' => $setting->type,
            'group' => $setting->group,
            'description' => $setting->description,
            'is_active' => $setting->is_active,
            'is_editable' => $setting->is_editable,
            'order' => $setting->order,
            'created_at' => optional($setting->created_at)?->toISOString(),
            'updated_at' => optional($setting->updated_at)?->toISOString(),
        ];

        if ($runtimeStatus !== null) {
            $payload['runtime_status'] = $runtimeStatus;
        }

        return $payload;
    }

    private function buildRuntimeStatuses(): array
    {
        $webSearchStatus = $this->webSearchService->adminStatus();

        return [
            'chatbot_primary_ai_provider' => $this->aiAssistantService->adminPrimaryProviderStatus(),
            'chatbot_web_search_enabled' => $webSearchStatus,
            'chatbot_web_search_provider' => $webSearchStatus,
        ];
    }

    private function canManageSystemSettings(Request $request): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $roleSlug = (string) ($user->role?->slug ?? '');
        if (in_array($roleSlug, ['finance_admin', 'support_admin', 'commercial_admin', 'sub_admin', 'admin'], true)) {
            return true;
        }

        return $user->hasPermission('system.settings.manage');
    }

    private function validateSystemSettingPayload(string $key, mixed $value): void
    {
        if ($key === 'chatbot_conversational_agents') {
            $agents = $this->parseConversationalAgentsPayload($value);
            $this->validateConversationalAgents($agents);
            return;
        }

        if ($key === 'chatbot_default_conversational_agent') {
            $this->validateDefaultConversationalAgent($value, $this->loadConfiguredConversationalAgents());
            return;
        }

        if ($key === 'chatbot_primary_ai_provider') {
            $this->validatePrimaryAiProvider($value);
            return;
        }

        if ($key === 'chatbot_web_search_provider') {
            $this->validateWebSearchProvider($value);
        }
    }

    private function validateBulkSettingsPayload(array $settings): void
    {
        $agents = null;

        if (array_key_exists('chatbot_conversational_agents', $settings)) {
            $agents = $this->parseConversationalAgentsPayload($settings['chatbot_conversational_agents']);
            $this->validateConversationalAgents($agents);
        }

        if (array_key_exists('chatbot_default_conversational_agent', $settings)) {
            $this->validateDefaultConversationalAgent(
                $settings['chatbot_default_conversational_agent'],
                $agents ?? $this->loadConfiguredConversationalAgents()
            );
        }

        if (array_key_exists('chatbot_primary_ai_provider', $settings)) {
            $this->validatePrimaryAiProvider($settings['chatbot_primary_ai_provider']);
        }

        if (array_key_exists('chatbot_web_search_provider', $settings)) {
            $this->validateWebSearchProvider($settings['chatbot_web_search_provider']);
        }
    }

    private function parseConversationalAgentsPayload(mixed $raw): array
    {
        $decoded = $raw;

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                throw ValidationException::withMessages([
                    'settings.chatbot_conversational_agents' => ['Le catalogue des agents conversationnels ne peut pas être vide.'],
                ]);
            }

            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                throw ValidationException::withMessages([
                    'settings.chatbot_conversational_agents' => ['Le catalogue des agents conversationnels doit être un JSON valide.'],
                ]);
            }
        }

        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'settings.chatbot_conversational_agents' => ['Le catalogue des agents conversationnels doit être un tableau JSON.'],
            ]);
        }

        if (array_is_list($decoded)) {
            return array_values(array_map(
                static fn ($entry): array => is_array($entry) ? $entry : [],
                $decoded,
            ));
        }

        $normalized = [];
        foreach ($decoded as $entryKey => $entryValue) {
            if (!is_array($entryValue)) {
                $entryValue = [];
            }

            $normalized[] = [
                'key' => $entryValue['key'] ?? (string) $entryKey,
                ...$entryValue,
            ];
        }

        return $normalized;
    }

    private function validateConversationalAgents(array $agents): void
    {
        $errors = [];
        $seenKeys = [];

        if ($agents === []) {
            $errors['settings.chatbot_conversational_agents'][] = 'Le catalogue des agents conversationnels doit contenir au moins un agent.';
        }

        foreach ($agents as $index => $agent) {
            $key = strtolower(trim((string) ($agent['key'] ?? '')));
            $label = trim((string) ($agent['label'] ?? ''));
            $provider = strtolower(trim((string) ($agent['provider'] ?? '')));

            if ($key === '') {
                $errors["settings.chatbot_conversational_agents.$index.key"][] = 'Chaque agent doit avoir une clé technique.';
            } elseif (in_array($key, $seenKeys, true)) {
                $errors["settings.chatbot_conversational_agents.$index.key"][] = 'Chaque clé agent doit être unique.';
            } else {
                $seenKeys[] = $key;
            }

            if ($label === '') {
                $errors["settings.chatbot_conversational_agents.$index.label"][] = 'Chaque agent doit avoir un libellé.';
            }

            if ($provider === '') {
                continue;
            }

            if (!in_array($provider, self::SUPPORTED_CHATBOT_PROVIDERS, true)) {
                $errors["settings.chatbot_conversational_agents.$index.provider"][] = "Le provider \"{$provider}\" n est pas supporté.";
                continue;
            }

            if (!$this->isProviderConfigured($provider)) {
                $agentName = $label !== '' ? $label : $key;
                $errors["settings.chatbot_conversational_agents.$index.provider"][] = "Le provider \"{$provider}\" de l agent \"{$agentName}\" n est pas exploitable: configurez au minimum la clé API NIMBA correspondante.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateDefaultConversationalAgent(mixed $value, array $agents): void
    {
        $defaultKey = strtolower(trim((string) $value));
        if ($defaultKey === '') {
            throw ValidationException::withMessages([
                'settings.chatbot_default_conversational_agent' => ['Choisissez un agent conversationnel par défaut valide.'],
            ]);
        }

        if ($agents === []) {
            return;
        }

        $availableKeys = array_map(
            static fn (array $agent): string => strtolower(trim((string) ($agent['key'] ?? ''))),
            $agents,
        );

        if (!in_array($defaultKey, $availableKeys, true)) {
            throw ValidationException::withMessages([
                'settings.chatbot_default_conversational_agent' => ['L agent conversationnel par défaut doit exister dans le catalogue configuré.'],
            ]);
        }
    }

    private function validateWebSearchProvider(mixed $value): void
    {
        $provider = strtolower(trim((string) $value));

        if ($provider === '' || !in_array($provider, self::SUPPORTED_WEB_SEARCH_PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'settings.chatbot_web_search_provider' => ['Choisissez un provider de recherche web valide: serper ou tavily.'],
            ]);
        }
    }

    private function validatePrimaryAiProvider(mixed $value): void
    {
        $provider = strtolower(trim((string) $value));

        if ($provider === '' || !in_array($provider, self::SUPPORTED_CHATBOT_PROVIDERS, true)) {
            throw ValidationException::withMessages([
                'settings.chatbot_primary_ai_provider' => ['Choisissez un provider IA principal valide: chatgpt, gemini ou claude.'],
            ]);
        }

        if (!$this->isProviderConfigured($provider)) {
            throw ValidationException::withMessages([
                'settings.chatbot_primary_ai_provider' => ["Le provider IA principal \"{$provider}\" n est pas exploitable: configurez au minimum la clé API NIMBA correspondante."],
            ]);
        }
    }

    private function loadConfiguredConversationalAgents(): array
    {
        $setting = $this->systemSettingRepository->getByKey('chatbot_conversational_agents');
        if (!$setting) {
            return [];
        }

        $raw = $setting->formatted_value ?? $setting->value;
        try {
            return $this->parseConversationalAgentsPayload($raw);
        } catch (ValidationException) {
            return [];
        }
    }

    private function isProviderConfigured(string $provider): bool
    {
        $apiKey = $this->resolveProviderConfigValue($provider, 'api_key');
        $baseUrl = $this->resolveProviderConfigValue($provider, 'base_url');

        return $apiKey !== '' && $baseUrl !== '';
    }

    private function resolveProviderConfigValue(string $provider, string $field): string
    {
        $providerValue = config("services.nimba_ai.providers.{$provider}.{$field}");
        $globalValue = match ($field) {
            'api_key' => config('services.nimba_ai.api_key'),
            'base_url' => config('services.nimba_ai.base_url'),
            'model' => config('services.nimba_ai.model'),
            default => null,
        };

        return $this->firstNonEmptyString($providerValue, $globalValue);
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    /**
     * 📋 Récupérer tous les paramètres système
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $group = $request->get('group');
            
            if ($group) {
                $settings = $this->systemSettingRepository->getSettingsByGroup($group);
            } else {
                $settings = $this->systemSettingRepository->getAll();
            }

            $runtimeStatuses = $this->buildRuntimeStatuses();
            $payload = $settings->map(fn ($setting) => $this->serializeSetting($setting, $runtimeStatuses))->values();

            return ApiResponseClass::sendResponse(
                $payload,
                'Paramètres système récupérés avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des paramètres système: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des paramètres');
        }
    }

    /**
     * 🔧 Récupérer les paramètres de paiement
     */
    public function getPaymentSettings(): JsonResponse
    {
        try {
            $settings = $this->systemSettingRepository->getPaymentSettings();

            return ApiResponseClass::sendResponse(
                $settings,
                'Paramètres de paiement récupérés avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des paramètres de paiement: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des paramètres de paiement');
        }
    }

    /**
     * 🛠️ Statut de maintenance (PUBLIC)
     *
     * Endpoint non-authentifié pour permettre à l'app mobile/web de savoir si
     * le serveur est en maintenance avant la connexion.
     */
    public function maintenanceStatus(): JsonResponse
    {
        try {
            $setting = $this->systemSettingRepository->getByKey('maintenance_mode');

            $rawValue = null;
            if ($setting) {
                // La plupart des implémentations exposent un "formatted_value"
                // (déjà casté). Sinon fallback sur "value".
                $rawValue = $setting->formatted_value ?? ($setting->value ?? null);
            }

            $isMaintenance = false;
            if (is_bool($rawValue)) {
                $isMaintenance = $rawValue;
            } elseif (is_int($rawValue) || is_float($rawValue)) {
                $isMaintenance = ((int) $rawValue) !== 0;
            } elseif (is_string($rawValue)) {
                $v = strtolower(trim($rawValue));
                $isMaintenance = in_array($v, ['1', 'true', 'yes', 'on'], true);
            }

            return ApiResponseClass::sendResponse(
                ['maintenance' => $isMaintenance],
                'Statut maintenance récupéré avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du statut maintenance: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération du statut maintenance');
        }
    }

    /**
     * ✏️ Mettre à jour un paramètre par clé
     */
    public function updateSetting(Request $request, string $key): JsonResponse
    {
        try {
            if (!$this->canManageSystemSettings($request)) {
                return ApiResponseClass::sendError(
                    'Permission insuffisante pour modifier les paramètres système.',
                    [],
                    Response::HTTP_FORBIDDEN
                );
            }

            $request->validate([
                'value' => 'required',
            ]);

            $this->validateSystemSettingPayload($key, $request->value);

            $setting = $this->systemSettingRepository->updateByKey($key, $request->value);

            if (!$setting) {
                return ApiResponseClass::notFound("Paramètre {$key} non trouvé");
            }

            $runtimeStatuses = $this->buildRuntimeStatuses();

            return ApiResponseClass::sendResponse(
                $this->serializeSetting($setting, $runtimeStatuses),
                "Paramètre {$key} mis à jour avec succès"
            );
        } catch (ValidationException $e) {
            return ApiResponseClass::sendError(
                'Validation des paramètres système échouée.',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du paramètre {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la mise à jour du paramètre');
        }
    }

    /**
     * 🔄 Mettre à jour plusieurs paramètres
     */
    public function updateMultipleSettings(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            if (!$this->canManageSystemSettings($request)) {
                return ApiResponseClass::sendError(
                    'Permission insuffisante pour modifier les paramètres système.',
                    [],
                    Response::HTTP_FORBIDDEN
                );
            }

            $request->validate([
                'settings' => 'required|array',
                'settings.*' => 'required',
            ]);

            $this->validateBulkSettingsPayload($request->settings);

            $results = $this->systemSettingRepository->updateMultiple($request->settings);

            $successCount = count(array_filter($results, fn($result) => $result !== null));
            $failureCount = count($request->settings) - $successCount;

            DB::commit();

            $response = [
                'success' => $failureCount === 0,
                'data' => $results,
                'summary' => [
                    'total' => count($request->settings),
                    'success' => $successCount,
                    'failure' => $failureCount,
                ],
                'message' => $failureCount === 0 
                    ? 'Tous les paramètres ont été mis à jour avec succès'
                    : "{$successCount} paramètre(s) mis à jour, {$failureCount} échec(s)",
            ];

            return ApiResponseClass::sendResponse(
                $response,
                $response['message']
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return ApiResponseClass::sendError(
                'Validation des paramètres système échouée.',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour multiple des paramètres: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la mise à jour multiple');
        }
    }

    /**
     * 👁️ Récupérer un paramètre par sa clé
     */
    public function showByKey(string $key): JsonResponse
    {
        try {
            $setting = $this->systemSettingRepository->getByKey($key);

            if (!$setting) {
                return ApiResponseClass::notFound('Paramètre système non trouvé');
            }

            return ApiResponseClass::sendResponse(
                $setting,
                'Paramètre système récupéré avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération du paramètre {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération du paramètre');
        }
    }

    /**
     * 👁️ Récupérer un paramètre par son ID
     */
    public function show(string $id): JsonResponse
    {
        try {
            $setting = $this->systemSettingRepository->getByID($id);

            if (!$setting) {
                return ApiResponseClass::notFound('Paramètre système non trouvé');
            }

            return ApiResponseClass::sendResponse(
                $setting,
                'Paramètre système récupéré avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération du paramètre {$id}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération du paramètre');
        }
    }

    /**
     * 🗑️ Supprimer un paramètre système
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $request = request();
            if (!$this->canManageSystemSettings($request)) {
                return ApiResponseClass::sendError(
                    'Permission insuffisante pour supprimer un paramètre système.',
                    [],
                    Response::HTTP_FORBIDDEN
                );
            }

            $setting = $this->systemSettingRepository->getByID($id);

            if (!$setting) {
                return ApiResponseClass::notFound('Paramètre système non trouvé');
            }

            $setting->delete();

            return ApiResponseClass::sendResponse(
                null,
                'Paramètre système supprimé avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression du paramètre {$id}: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la suppression du paramètre');
        }
    }

    /**
     * ✅ Vérifier si les paiements sont activés pour un type d'utilisateur
     */
    public function checkPaymentEnabled(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_type' => 'required|in:client,pro,sub_admin',
            ]);

            $enabled = false;
            $key = null;
            
            switch($request->user_type) {
                case 'client':
                    $key = 'client_payments_enabled';
                    break;
                case 'pro':
                    $key = 'pro_payments_enabled';
                    break;
                case 'sub_admin':
                    $key = 'sub_admin_payments_enabled';
                    break;
            }

            $setting = $this->systemSettingRepository->getByKey($key);
            $enabled = $setting ? (bool) $setting->formatted_value : false;

            return ApiResponseClass::sendResponse([
                'enabled' => $enabled,
                'message' => $enabled 
                    ? "Les paiements sont activés pour les {$this->getUserTypeLabel($request->user_type)}"
                    : "Les paiements sont temporairement désactivés pour les {$this->getUserTypeLabel($request->user_type)}",
            ], 'Vérification terminée');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification des paiements: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la vérification');
        }
    }

    private function getUserTypeLabel($userType)
    {
        return match($userType) {
            'client' => 'clients',
            'pro' => 'professionnels',
            'sub_admin' => 'sous-administrateurs',
            default => 'utilisateurs',
        };
    }
}