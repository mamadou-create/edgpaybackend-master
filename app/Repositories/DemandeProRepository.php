<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Helpers\HelperStatus;
use App\Helpers\UploadHelper;
use App\Interfaces\DemandeProRepositoryInterface;
use App\Models\DemandePro;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class DemandeProRepository implements DemandeProRepositoryInterface
{

    /**
     * Authenticated User Instance.
     *
     * @var User
     */
    public ?User $user;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->user = Auth::guard()->user();
    }


    public function getAll()
    {
        try {
             
              if ($this->user->role->slug === RoleEnum::SUPER_ADMIN) {
                // Super-admin ou admin : retourne tous les wallets
                return DemandePro::with('user')->latest()->get();
            } else {
                // Sous-admin : retourne seulement les wallets de leurs utilisateurs assignés
                return DemandePro::with('user')
                    ->whereHas('user', function ($query) {
                        $query->where('assigned_user', $this->user->id)
                            ->whereNull('deleted_at'); // exclut les users supprimés
                    })
                    ->latest()
                    ->get();
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            return [];
        }
    }

    public function create(array $data)
    {
        try {
            // Vérifier si l'utilisateur est déjà pro
            if ($this->user && $this->user->is_pro) {
                throw new \Exception('Vous êtes déjà un utilisateur professionnel.');
            }
            $titleShort = 'piece_' . uniqid();

            if (!empty($data['piece_image_path'])) {
                $uploadedPath = UploadHelper::upload(
                    $data['piece_image_path'],
                    $titleShort . '-' . time(),
                    'uploads/demandes_pro'
                );

                if ($uploadedPath) {
                    // ⚡ On enregistre uniquement le chemin relatif
                    $data['piece_image_path'] = $uploadedPath;
                }
            }

            return DemandePro::create($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la demande pro : ' . $e->getMessage());
            throw $e;
        }
    }

    public function delete(string $id)
    {
        try {
            $demandePro = DemandePro::findOrFail($id);

            if (!empty($demandePro->piece_image_path)) {
                // ⚡ Ici on supprime avec le chemin relatif
                UploadHelper::deleteFile($demandePro->piece_image_path);
            }

            return $demandePro->delete();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de la demande pro : ' . $e->getMessage());
            throw $e;
        }
    }



    public function getByID(string $id)
    {
        try {
            return DemandePro::with('user')->findOrFail($id);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la demande pro: ' . $e->getMessage());
            throw $e;
        }
    }

    public function update(string $id, array $data)
    {
        try {
            $demandePro = DemandePro::findOrFail($id);

            if (!empty($data['piece_image_path']) && $data['piece_image_path'] instanceof \Illuminate\Http\UploadedFile) {
                $titleShort = 'piece_' . uniqid();
                $data['piece_image_path'] = UploadHelper::update(
                    $data['piece_image_path'],
                    $titleShort . '-' . time(),
                    'uploads/demandes_pro',
                    $demandePro->piece_image_path
                );
            } else {
                // Si piece_image_path n'est pas un fichier uploadé, garder l'ancienne valeur
                unset($data['piece_image_path']);
            }

            return $demandePro->update($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de la demande pro: ' . $e->getMessage());
            throw $e;
        }
    }



    public function findByStatus(string $status): iterable
    {
        try {
            return DemandePro::with('user')->where('status', $status)->latest()->get();
        } catch (\Exception $e) {
            report($e);
            return [];
        }
    }

    public function findByUser(string $userId)
    {
        try {

            return DemandePro::with('user')->where('user_id', $userId)->latest()->first();
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    public function updateStatus(string $id, string $status): bool
    {
        try {
            return DemandePro::where('id', $id)->update([
                'status' => $status,
                'date_decision' => now(),
            ]) > 0;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Annuler une demande (avec soft delete si le statut est "en_cours")
     */
    public function cancel(string $id, ?string $reason): bool
    {
        try {
            $demande = DemandePro::findOrFail($id);

            // Vérifier si la demande peut être annulée
            if (!in_array($demande->status, [HelperStatus::EN_ATTENTE])) {
                logger()->warning("Impossible d'annuler la demande $id - Statut actuel: {$demande->status}");
                return false;
            }

            // Mettre à jour le statut et la raison d'annulation
            $updateData = ['status' => HelperStatus::ANNULE];

            if ($reason) {
                $updateData['cancellation_reason'] = $reason;
                $updateData['cancelled_at'] = now();
            }

            $success = $demande->update($updateData);

            // if (!empty($demandePro->piece_image_path)) {
            //     // ⚡ Ici on supprime avec le chemin relatif
            //     UploadHelper::deleteFile($demande->piece_image_path);
            // }


            // Si le statut était "en attente", appliquer le soft delete
            if ($success && $demande->status === HelperStatus::EN_ATTENTE) {
                $demande->delete();
                logger()->info("Demande $id annulée et soft delete appliqué (statut était en attente)");
            }

            return $success;
        } catch (ModelNotFoundException $e) {
            logger()->warning("Demande non trouvée pour annulation, ID : $id");
            return false;
        } catch (\Exception $e) {
            logger()->error("Erreur lors de l'annulation de la demande $id : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restaurer une demande supprimée
     */
    public function restore(string $id): bool
    {
        try {
            $demande = DemandePro::onlyTrashed()->findOrFail($id);
            return $demande->restore();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Demande supprimée non trouvée pour restauration, ID : $id");
            return false;
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la restauration de la demande $id : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer définitivement une demande
     */
    public function forceDelete(string $id): bool
    {
        try {
            $demande = DemandePro::onlyTrashed()->findOrFail($id);
            return (bool) $demande->forceDelete();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Demande supprimée non trouvée pour suppression définitive, ID : $id");
            return false;
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la suppression définitive de la demande $id : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupérer les demandes supprimées
     */
    public function getTrashed(): iterable
    {
        try {
            return DemandePro::onlyTrashed()
                ->latest('deleted_at')
                ->get();
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la récupération des demandes supprimées : " . $e->getMessage());
            return collect();
        }
    }

    /**
     * Trouver une demande supprimée par son ID
     */
    public function findTrashedById(string $id): ?object
    {
        try {
            return DemandePro::with('user')->onlyTrashed()->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return null;
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la recherche de la demande supprimée $id : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifier si une demande peut être annulée
     */
    public function canBeCancelled(string $id): bool
    {
        try {
            $topupRequest = DemandePro::select('status')->findOrFail($id);

            // ✅ Seules les demandes en attente peuvent être annulées
            return $topupRequest->status === HelperStatus::EN_ATTENTE;
        } catch (\Exception $e) {
            logger()->warning("Échec de vérification d'annulation pour la demande $id : " . $e->getMessage());
            return false;
        }
    }


    /**
     * Récupérer les demandes annulées (inclut les soft deleted)
     */
    public function findCancelled(): iterable
    {
        try {
            return DemandePro::with('user')->withTrashed()
                ->where('status', HelperStatus::ANNULE)
                ->latest('cancelled_at')
                ->get();
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la récupération des demandes annulées : " . $e->getMessage());
            return collect();
        }
    }
}
