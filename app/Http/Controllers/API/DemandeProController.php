<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\DemandeProRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Helpers\HelperStatus;
use App\Http\Requests\DemandePro\DemandeProRequest;
use App\Http\Resources\DemandeProResource;
use App\Models\Role;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DemandeProController extends Controller
{
    private $demandeProRepository;
    private $walletService;

    public function __construct(
        DemandeProRepositoryInterface $demandeProRepository,
        WalletService $walletService
    ) {
        $this->demandeProRepository = $demandeProRepository;
        $this->walletService = $walletService;
    }

    public function index(Request $request)
    {
        try {
            $status = $request->query('status');
            $demandes = $status
                ? $this->demandeProRepository->findByStatus($status)
                : $this->demandeProRepository->getAll();

            return ApiResponseClass::sendResponse(
                DemandeProResource::collection($demandes),
                'Demandes récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }


    public function store(DemandeProRequest $request)
    {
        DB::beginTransaction();
        try {
            $demande = $this->demandeProRepository->create($request->validated());

            DB::commit();
            return ApiResponseClass::created(
                new DemandeProResource($demande),
                'Demande envoyée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            if ($e->getMessage() === 'Vous êtes déjà un utilisateur professionnel.') {
                return ApiResponseClass::sendError(
                    'Action non autorisée',
                    ['message' => $e->getMessage()],
                    Response::HTTP_FORBIDDEN
                );
            }

            return ApiResponseClass::rollback($e, "Erreur lors de l'enregistrement de la demande");
        }
    }



    public function show($id)
    {
        try {
            $demande = $this->demandeProRepository->getByID($id);
            if (!$demande) {
                return ApiResponseClass::notFound('Demande introuvable');
            }

            return ApiResponseClass::sendResponse(
                new DemandeProResource($demande),
                'Demande récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération de la demande');
        }
    }

    /**
     * 🔍 Pro demande par utilisateur
     */
    public function findByUser($userId)
    {
        try {
            $demande = $this->demandeProRepository->findByUser($userId);

            if (!$demande) {
                return ApiResponseClass::notFound('Aucune demande trouvée pour cet utilisateur');
            }
            return ApiResponseClass::sendResponse(
                new DemandeProResource($demande),
                'Demande récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération de demande pro");
        }
    }


    public function update(DemandeProRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $this->demandeProRepository->update($id, $request->validated());
            $demande = $this->demandeProRepository->getByID($id);

            DB::commit();
            return ApiResponseClass::sendResponse(
                new DemandeProResource($demande),
                'Demande mise à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour de la demande");
        }
    }

    public function updateStatus(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'status' => ['required', 'in:' . implode(',', HelperStatus::getDemandeProStatuses())],
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::sendError('Validation Error.', $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Mise à jour du statut de la demande
            $success = $this->demandeProRepository->updateStatus($id, $request->input('status'));
           
            if ($success) {
                // Récupérer la demande mise à jour
                $demande = $this->demandeProRepository->getByID($id);
                $role = Role::where('slug', RoleEnum::PRO)->first();
                // Si la demande est acceptée, mettre à jour l'utilisateur
                if ($demande && $demande->status === HelperStatus::ACCEPTE) {
                    $user = $demande->user;
                    if ($user) {
                        $user->is_pro = true;
                        $user->assigned_user = Auth::id();
                        $user->role_id = $role->id;
                        $user->save();


                        // Créer automatiquement le wallet pour l'utilisateur
                        $walletResult = $this->walletService->createWalletForUser($user->id);

                        if ($walletResult && $walletResult['created']) {
                            logger()->info("Wallet créé automatiquement pour l'utilisateur professionnel: " . $user->id);
                        }
                    }
                }

                DB::commit();
                return ApiResponseClass::sendResponse(null, 'Statut mis à jour avec succès');
            } else {
                DB::rollBack();
                return ApiResponseClass::notFound('Demande introuvable');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du statut");
        }
    }


    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $this->demandeProRepository->delete($id);

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Demande supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de la demande");
        }
    }

    /**
     * Annuler une demande
     */
    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Vérifier si la demande peut être annulée
            if (!$this->demandeProRepository->canBeCancelled($id)) {
                return ApiResponseClass::sendError(
                    'Impossible d\'annuler cette demande',
                    ['message' => 'La demande ne peut pas être annulée dans son statut actuel'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $success = $this->demandeProRepository->cancel($id, $request->reason);

            if ($success) {
                // Récupérer la demande (peut être soft delete si statut était "en attente")
                $demande = $this->demandeProRepository->getByID($id);
                if (!$demande) {
                    $demande = $this->demandeProRepository->findTrashedById($id);
                }

                DB::commit();

                $message = 'Demande annulée avec succès';
                if ($demande && $demande->isTrashed()) {
                    $message .= ' (demande archivée)';
                }

                return ApiResponseClass::sendResponse(
                    $demande ? new DemandeProResource($demande) : null,
                    $message
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de l\'annulation',
                    ['message' => 'Impossible d\'annuler la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de l'annulation de la demande");
        }
    }

    /**
     * Restaurer une demande supprimée
     */
    public function restore($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->demandeProRepository->restore($id);

            if ($success) {
                $demande = $this->demandeProRepository->getByID($id);

                DB::commit();
                return ApiResponseClass::sendResponse(
                    new DemandeProResource($demande),
                    'Demande restaurée avec succès'
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de la restauration',
                    ['message' => 'Impossible de restaurer la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la restauration de la demande");
        }
    }

    /**
     * Supprimer définitivement une demande
     */
    public function forceDelete($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->demandeProRepository->forceDelete($id);

            if ($success) {
                DB::commit();
                return ApiResponseClass::sendResponse(
                    [],
                    'Demande supprimée définitivement avec succès'
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de la suppression',
                    ['message' => 'Impossible de supprimer définitivement la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression définitive de la demande");
        }
    }

    /**
     * Récupérer les demandes supprimées
     */
    public function trashed(Request $request)
    {
        try {
            $demandes = $this->demandeProRepository->getTrashed();

            return ApiResponseClass::sendResponse(
                DemandeProResource::collection($demandes),
                'Demandes supprimées récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes supprimées');
        }
    }

    /**
     * Récupérer les demandes annulées (inclut les soft deleted)
     */
    public function cancelled(Request $request)
    {
        try {
            $demandes = $this->demandeProRepository->findCancelled();

            return ApiResponseClass::sendResponse(
                DemandeProResource::collection($demandes),
                'Demandes annulées récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes annulées');
        }
    }
}
