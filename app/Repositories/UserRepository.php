<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Interfaces\UserRepositoryInterface;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function PHPUnit\Framework\isEmpty;

class UserRepository implements UserRepositoryInterface
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
            return User::with(['wallet', 'role', 'user_assigned'])->get();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            return [];
        }
    }

    public function getAllRoles()
    {
        try {
            return Role::orderBy('name', 'asc')->get();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            return [];
        }
    }

    public function getAllRolesWithClient()
    {
        try {
            return Role::where('slug', '!=', [RoleEnum::CLIENT])
                ->orderBy('name', 'asc')
                ->get();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des rôles : ' . $e->getMessage());
            return [];
        }
    }


    public function getAllByUserAssigned(string $assigned_user)
    {
        try {
            return User::with(['wallet', 'role', 'user_assigned'])->where('assigned_user', $assigned_user)->get();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            return [];
        }
    }

    public function create(array $data)
    {
        try {
            DB::beginTransaction();

            // Récupération du rôle - correction des variables
            $roleModel = Role::where('slug', RoleEnum::CLIENT)->first();

            if (!$roleModel) {
                throw new \Exception("Rôle non trouvé");
            }

            $roleSlug = $roleModel->slug;
            $roleId = $roleModel->id;

            // Définition du statut selon le rôle : utiliser $roleSlug au lieu de $roleName
            $status = ($roleSlug === RoleEnum::CLIENT) ? true : false;
            $email_verified_at = ($roleSlug === RoleEnum::CLIENT) ? null : now();


            // ✅ DÉFINIR AUTOMATIQUEMENT is_pro BASÉ SUR LE RÔLE
            // Si le rôle est PRO, alors is_pro doit être true
            $isPro = $data['is_pro'] ?? false;
            // Vérifier si le rôle correspond à un PRO
            if ($roleSlug == RoleEnum::PRO) {
                $isPro = true;
            }


            $userData = [
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'display_name' => $data['display_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'role_id' => $data['role_id'] ?? $roleId,
                'is_pro' => $isPro,
                'status' => $status,
                'email_verified_at' => $email_verified_at,
                'assigned_user' => $this->user->id ?? null,
                'solde_portefeuille' => $data['solde_portefeuille'] ?? 0,
                'commission_portefeuille' => $data['commission_portefeuille'] ?? 0,
                'two_factor_enabled' => $data['two_factor_enabled'] ?? false,
            ];

            $user = User::create($userData);

            // Log -: utiliser $roleSlug au lieu de $roleName
            $state = $status ? 'activé' : 'en attente d\'activation';
            Log::info("Utilisateur {$roleSlug} créé ({$state}) : {$user->id}");

            DB::commit();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de l\'utilisateur : ' . $e->getMessage());
            throw $e;
        }
    }



    public function delete(string $id)
    {
        try {
            $user = User::findOrFail($id);
            return $user->delete();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression de l\'utilisateur: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getByID(string $id)
    {
        try {
            return User::with('user_assigned')->findOrFail($id);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de l\'utilisateur: ' . $e->getMessage());
            throw $e;
        }
    }

    public function update(string $id, array $data)
    {
        try {
            $user = User::findOrFail($id);
            return $user->update($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'utilisateur: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateStatus(string $id, bool $status)
    {
        try {
            $user = User::findOrFail($id);
            $user->status = $status;
            $user->save();

            return $user;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du statut utilisateur : " . $e->getMessage());
            throw $e;
        }
    }


    public function findByEmail(string $email)
    {
        try {
            return User::with('user_assigned')->where('email', $email)->first();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche par email: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findByPhone(string $phone)
    {
        try {
            return User::with('user_assigned')->where('phone', $phone)->first();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche par phone: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findByPasswordResetToken($token)
    {
        try {
            return User::where('password_reset_token', $token)->first();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche par token de réinitialisation: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findByActivationToken($token)
    {
        try {
            return User::where('activation_token', $token)->first();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche par token d\'activation: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findByOtp($token)
    {
        try {
            return User::where('otp', $token)->first();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche par token d\'activation: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update User By ID.
     *
     * @param int $id
     * @param array $data
     * @return object Updated User Object
     */
    public function updatePassword(string $id, array $data)
    {
        try {
            $user = User::findOrFail($id);

            if (empty($data['password'])) {
                return $user; // pas de modification si mot de passe vide
            }

            // Hache le nouveau mot de passe
            $user->password = Hash::make($data['password']);
            $user->save(); // Enregistre l'utilisateur

            return $user; // Retourne l'utilisateur mis à jour
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de mot de passe: ' . $e->getMessage());
            throw $e;
        }
    }
}
