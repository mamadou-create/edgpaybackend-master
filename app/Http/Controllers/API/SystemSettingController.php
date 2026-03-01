<?php
// app/Http\Controllers\API\SystemSettingController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\SystemSettingRepositoryInterface;
use App\Classes\ApiResponseClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemSettingController extends Controller
{
    private SystemSettingRepositoryInterface $systemSettingRepository;

    public function __construct(SystemSettingRepositoryInterface $systemSettingRepository)
    {
        $this->systemSettingRepository = $systemSettingRepository;
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

            return ApiResponseClass::sendResponse(
                $settings,
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
            $request->validate([
                'value' => 'required',
            ]);

            $setting = $this->systemSettingRepository->updateByKey($key, $request->value);

            if (!$setting) {
                return ApiResponseClass::notFound("Paramètre {$key} non trouvé");
            }

            return ApiResponseClass::sendResponse(
                $setting,
                "Paramètre {$key} mis à jour avec succès"
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du paramètre {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la mise à jour du paramètre');
        }
    }

    /**
     * 🔄 Mettre à jour plusieurs paramètres
     */
    public function updateMultipleSettings(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'settings' => 'required|array',
                'settings.*' => 'required',
            ]);

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
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour multiple des paramètres: ' . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la mise à jour multiple');
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