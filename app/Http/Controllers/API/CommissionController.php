<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\CommissionRepositoryInterface;
use App\Classes\ApiResponseClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CommissionController extends Controller
{
    private CommissionRepositoryInterface $commissionRepository;

    public function __construct(CommissionRepositoryInterface $commissionRepository)
    {
        $this->commissionRepository = $commissionRepository;
    }

    /**
     * 📋 Récupérer toutes les commissions
     */
    public function index(Request $request): JsonResponse
    {
        try {
             $commissions = $this->commissionRepository->getAll();

            return ApiResponseClass::sendResponse(
                $commissions,
                'Commissions récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des commissions: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des commissions');
        }
    }

    /**
     * 👁️ Récupérer une commission par son ID
     */
    public function show(string $id): JsonResponse
    {
        try {
            $commission = $this->commissionRepository->getByID($id);

            if (!$commission) {
                return ApiResponseClass::notFound('Commission non trouvée');
            }

            return ApiResponseClass::sendResponse(
                $commission,
                'Commission récupérée avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération de la commission {$id}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération de la commission');
        }
    }

    /**
     * 👁️ Récupérer une commission par sa clé
     */
    public function showByKey(string $key): JsonResponse
    {
        try {
            $commission = $this->commissionRepository->getByKey($key);

            if (!$commission) {
                return ApiResponseClass::notFound('Commission non trouvée');
            }

            return ApiResponseClass::sendResponse(
                $commission,
                'Commission récupérée avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération de la commission {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération de la commission');
        }
    }

    /**
     * ➕ Créer une nouvelle commission
     */
    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'key' => 'required|string|unique:commissions,key|max:255',
                'value' => 'required|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::validationError($validator->errors());
            }

            $commission = $this->commissionRepository->create($validator->validated());

            DB::commit();

            return ApiResponseClass::sendResponse(
                $commission,
                'Commission créée avec succès',
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la commission: ' . $e->getMessage());
            
            $message = 'Erreur lors de la création de la commission';
            if (str_contains($e->getMessage(), 'already exists')) {
                $message = 'Une commission avec cette clé existe déjà';
            }
            
            return ApiResponseClass::serverError($message);
        }
    }

    /**
     * ✏️ Mettre à jour une commission par son ID
     */
    public function update(Request $request, string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'key' => 'sometimes|string|max:255',
                'value' => 'sometimes|numeric|min:0|max:100',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::sendError($validator->errors());
            }

            $commission = $this->commissionRepository->update($id, $validator->validated());

            if (!$commission) {
                return ApiResponseClass::notFound('Commission non trouvée');
            }

            DB::commit();

            return ApiResponseClass::sendResponse(
                $commission,
                'Commission mise à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la mise à jour de la commission {$id}: " . $e->getMessage());
            
            $message = 'Erreur lors de la mise à jour de la commission';
            if (str_contains($e->getMessage(), 'already exists')) {
                $message = 'Une commission avec cette clé existe déjà';
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $message = 'Commission non trouvée';
            }
            
            return ApiResponseClass::serverError($message);
        }
    }

    /**
     * ✏️ Mettre à jour une commission par sa clé
     */
    public function updateByKey(Request $request, string $key): JsonResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'value' => 'required|numeric|min:0|max:100',
            ]);

            $commission = $this->commissionRepository->updateByKey($key, $request->value);

            DB::commit();

            return ApiResponseClass::sendResponse(
                $commission,
                "Commission {$key} mise à jour avec succès"
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la mise à jour de la commission {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la mise à jour de la commission');
        }
    }

    /**
     * 🔄 Mettre à jour plusieurs commissions
     */
    public function updateMultiple(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $request->validate([
                'commissions' => 'required|array',
                'commissions.*.key' => 'required|string',
                'commissions.*.value' => 'required|numeric|min:0|max:100',
            ]);

            $results = [];
            $successCount = 0;
            $failureCount = 0;

            foreach ($request->commissions as $commissionData) {
                try {
                    $commission = $this->commissionRepository->updateByKey(
                        $commissionData['key'], 
                        $commissionData['value']
                    );
                    $results[$commissionData['key']] = [
                        'success' => true,
                        'data' => $commission
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[$commissionData['key']] = [
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $failureCount++;
                }
            }

            DB::commit();

            $response = [
                'success' => $failureCount === 0,
                'data' => $results,
                'summary' => [
                    'total' => count($request->commissions),
                    'success' => $successCount,
                    'failure' => $failureCount,
                ],
                'message' => $failureCount === 0 
                    ? 'Toutes les commissions ont été mises à jour avec succès'
                    : "{$successCount} commission(s) mise(s) à jour, {$failureCount} échec(s)",
            ];

            return ApiResponseClass::sendResponse(
                $response,
                $response['message']
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour multiple des commissions: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la mise à jour multiple des commissions');
        }
    }

    /**
     * 🗑️ Supprimer une commission par son ID
     */
    public function destroy(string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $commission = $this->commissionRepository->getByID($id);

            if (!$commission) {
                return ApiResponseClass::notFound('Commission non trouvé');
            }

            $commission->delete();

            DB::commit();

            return ApiResponseClass::sendResponse(
                null,
                'Paramètre système supprimé avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la suppression du paramètre {$id}: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la suppression du paramètre');
        }
    }

    /**
     * 🗑️ Supprimer une commission par sa clé
     */
    public function destroyByKey(string $key): JsonResponse
    {
        DB::beginTransaction();
        try {
            $commission = $this->commissionRepository->getByKey($key);
            
            if (!$commission) {
                return ApiResponseClass::notFound('Commission non trouvée');
            }

             $commission->delete();

            DB::commit();

            return ApiResponseClass::sendResponse(
                null,
                'Commission supprimée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la suppression de la commission {$key}: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la suppression de la commission');
        }
    }



    /**
     * ✅ Vérifier et obtenir la commission par défaut
     */
    public function getDefaultCommission(): JsonResponse
    {
        try {
            $commission = $this->commissionRepository->getByKey('default_commission_rate');

            if (!$commission) {
                // Créer la commission par défaut si elle n'existe pas
                $commission = $this->commissionRepository->create([
                    'key' => 'default_commission_rate',
                    'value' => 0.01 // 1%
                ]);
            }

            return ApiResponseClass::sendResponse(
                $commission,
                'Commission par défaut récupérée avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la commission par défaut: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération de la commission par défaut');
        }
    }

}