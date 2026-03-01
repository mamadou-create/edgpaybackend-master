<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Http\Requests\User\RegisterRequest;
use App\Http\Requests\User\UpdatePasswordRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Mail\AccountCreatedMail;
use App\Mail\AccountActivatedMail;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

use App\Models\User;

class UserController extends Controller
{
    private $userRepository;
    private $walletService;

    public function __construct(
        UserRepositoryInterface $userRepository,
        WalletService $walletService
    ) {
        $this->userRepository = $userRepository;
        $this->walletService = $walletService;
    }

    public function index(Request $request)
    {
        try {
            $q = trim((string) $request->query('q', ''));
            $compact = filter_var($request->query('compact', false), FILTER_VALIDATE_BOOLEAN);
            $limit = (int) $request->query('limit', 50);
            $limit = max(1, min($limit, 100));
            $role = trim((string) $request->query('role', ''));

            // Mode léger pour sélecteurs UI (liste/recherche rapide)
            if ($compact || $q !== '' || $role !== '') {
                $query = User::query()
                    ->select(['id', 'display_name', 'phone', 'email', 'created_at'])
                    ->orderByDesc('created_at');

                if ($role === RoleEnum::CLIENT->value) {
                    $query->whereHas('role', function ($r) {
                        $r->where('slug', RoleEnum::CLIENT->value);
                    });
                }

                if ($q !== '') {
                    $digits = preg_replace('/\D+/', '', $q);
                    $like = '%' . $q . '%';
                    $query->where(function ($w) use ($q, $like, $digits) {
                        $w->where('id', $q)
                            ->orWhere('phone', 'like', $like)
                            ->when($digits !== '', function ($w2) use ($digits) {
                                // Recherche téléphone robuste (ignore espaces, +, -, parenthèses)
                                // Ex: "621111111" match "621 11 11 11" ou "+224 621 11 11 11".
                                $w2->orWhereRaw(
                                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-',''),'(',''),')','') LIKE ?",
                                    ['%' . $digits . '%']
                                );
                            })
                            ->orWhere('display_name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
                }

                $users = $query->limit($limit)->get();

                // Retour volontairement minimal (pas de relations) pour éviter les gros payloads.
                $payload = $users->map(function ($u) {
                    return [
                        'id' => (string) $u->id,
                        'display_name' => $u->display_name,
                        'phone' => $u->phone,
                        'email' => $u->email,
                    ];
                });

                return ApiResponseClass::sendResponse($payload, 'Utilisateurs récupérés avec succès');
            }

            // Mode complet (compatibilité)
            $users = $this->userRepository->getAll();

            return ApiResponseClass::sendResponse(UserResource::collection($users), 'Utilisateurs récupérés avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des utilisateurs');
        }
    }

    public function getUsersByAssigned(Request $request)
    {
        try {
            $assigned_user = $request->query('assigned_user');
            $users = $this->userRepository->getAllByUserAssigned($assigned_user);

            return ApiResponseClass::sendResponse(UserResource::collection($users), 'Utilisateurs récupérés avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des utilisateurs');
        }
    }


    // public function getAllRoles()
    // {
    //     try {
    //         $roles = $this->userRepository->getAllRoles();

    //         return ApiResponseClass::sendResponse(RoleResource::collection($roles), 'Roles récupérés avec succès');
    //     } catch (\Exception $e) {
    //         return ApiResponseClass::serverError('Erreur lors de la récupération des utilisateurs');
    //     }
    // }

    public function getAllRoles()
    {
        try {
            // Récupérer l'utilisateur connecté
            $user = Auth::guard()->user();

            if ($user->role->slug === RoleEnum::SUPER_ADMIN) {
                // Super-admin : retourne tous les rôles sauf api_client
                $roles = $this->userRepository->getAllRoles()->filter(function ($role) {
                    return $role->slug !== 'api_client';
                });
            } elseif ($user->role->slug === RoleEnum::PRO) {
                // Pro : retourne seulement Client (et exclut api_client)
                $roles = Role::whereIn('slug', [RoleEnum::CLIENT])
                    ->where('slug', '!=', 'api_client')
                    ->orderBy('name', 'asc')
                    ->get();
            } else {
                // Autres rôles : retourne seulement Pro (et exclut api_client)
                $roles = Role::whereIn('slug', [RoleEnum::PRO])
                    ->where('slug', '!=', 'api_client')
                    ->orderBy('name', 'asc')
                    ->get();
            }

            return ApiResponseClass::sendResponse(RoleResource::collection($roles), 'Rôles récupérés avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des rôles: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des rôles');
        }
    }

    public function store(RegisterRequest $request)
    {
        DB::beginTransaction();
        try {

            $user = $this->userRepository->create($request->validated());



            if ($user->role !== RoleEnum::CLIENT) {
                // Créer automatiquement le wallet pour l'utilisateur
                $walletResult = $this->walletService->createWalletForUser($user->id);

                if ($walletResult && $walletResult['created']) {
                    logger()->info("Wallet créé automatiquement pour l'utilisateur professionnel: " . $user->id);
                }
            }

            DB::commit();

            // Notification mail au nouvel utilisateur
            if (!empty($user->email)) {
                try {
                    Mail::to($user->email)->send(new AccountCreatedMail($user));
                } catch (\Throwable $e) {
                    Log::error('Erreur envoi mail AccountCreatedMail : ' . $e->getMessage());
                }
            }

            return ApiResponseClass::created([
                'user' => new UserResource($user),
            ], 'Utilisateur créé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de l'utilisateur");
        }
    }

    public function show($id)
    {
        try {
            $user = $this->userRepository->getByID($id);
            return ApiResponseClass::sendResponse(new UserResource($user), 'Utilisateur récupéré avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::notFound('Utilisateur non trouvé');
        }
    }

    public function update(UpdateUserRequest $request, $id)
    {
        DB::beginTransaction();
        try {

            $userData = $request->validated();

            $this->userRepository->update($id, $userData);
            $user = $this->userRepository->getByID($id);

            DB::commit();
            return ApiResponseClass::sendResponse(new UserResource($user), 'Utilisateur mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour de l'utilisateur");
        }
    }

    public function updateStatus(Request $request, string $id)
    {
        DB::beginTransaction();
        try {
            $status = $request->input('status');
            $this->userRepository->updateStatus($id, $status);
            $user = $this->userRepository->getByID($id);

            DB::commit();

            // Notification mail quand le compte est activé par l'admin
            if ($status === true || $status === 1 || $status === '1' || $status === 'true') {
                if (!empty($user->email)) {
                    try {
                        Mail::to($user->email)->send(new AccountActivatedMail($user));
                    } catch (\Throwable $e) {
                        Log::error('Erreur envoi mail AccountActivatedMail : ' . $e->getMessage());
                    }
                }
            }

            return ApiResponseClass::sendResponse(new UserResource($user), 'Statut mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du statut de l'utilisateur");
        }
    }


    public function updatePassword(UpdatePasswordRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'password' => 'required|string|min:4|max:6|confirmed',
                'old_password' => ['required'],
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::sendError('Erreur de validation', $validator->errors(), 422);
            }

            $user = $this->userRepository->getByID($id);

            if (!Hash::check($request->old_password, $user->password)) {
                return ApiResponseClass::sendError(
                    'L\'ancien mot de passe n\'est pas valide.',
                    null,
                    422
                );
            }

            $this->userRepository->updatePassword($id, [
                'password' => $request->password
            ]);

            DB::commit();

            // Ne renvoyer que les informations essentielles, pas tout l'utilisateur
            return ApiResponseClass::sendResponse(
                null,
                'Mot de passe mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::throw($e, "Erreur lors de la mise à jour du mot de passe");
        }
    }

    public function updatePasswordForUser(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'password' => 'required|string|min:4|max:6|confirmed',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::sendError('Erreur de validation', $validator->errors(), 422);
            }

            $this->userRepository->updatePassword($id, [
                'password' => $request->password
            ]);

            DB::commit();

            return ApiResponseClass::sendResponse(
                null,
                'Mot de passe mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::throw($e, "Erreur lors de la mise à jour du mot de passe");
        }
    }


    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $this->userRepository->delete($id);

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Utilisateur supprimé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de l'utilisateur");
        }
    }
}
