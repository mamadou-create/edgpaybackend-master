<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\User\ForgotPasswordEmailRequest;
use App\Http\Requests\User\ForgotPasswordRequest;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Mail\ActivationMail;
use App\Mail\ResetPasswordMail;
use App\Services\NimbaSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    private $userRepository;

    public function __construct(
        UserRepositoryInterface $userRepository,
        private NimbaSmsService $smsService
    ) {
        $this->userRepository = $userRepository;
    }

    /**
     * 🔹 Enregistrement d'un nouvel utilisateur
     */
    public function register(Request $request)
    {
        DB::beginTransaction();
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:4|max:6|confirmed',
                'phone' => 'required|string|max:20|unique:users', // ✅ Maintenant requis
                'display_name' => 'sometimes|string|max:255',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::sendError('Validation Error', $validator->errors(), 422);
            }

            $userData = $request->all();
            $user = $this->userRepository->create($userData);

            // 📱 Génération token d'activation et envoi SMS
            $user->generateActivationToken();

            // Envoi SMS d'activation
            $smsResult = $this->smsService->sendSingleSms(
                'MDING',
                $user->phone,
                "Votre code d'activation EDGPAY-MDING est : {$user->otp}. Utilisez-le pour activer votre compte."
            );

            $activationVia = 'sms';
            if (!$smsResult['success']) {
                if ($user->email) {
                    try {
                        Mail::to($user->email)->send(new ActivationMail($user));
                        $activationVia = 'email';
                    } catch (\Throwable $mailEx) {
                        Log::error('Fallback email activation échoué', ['error' => $mailEx->getMessage(), 'user_id' => $user->id]);
                        DB::rollBack();
                        return ApiResponseClass::sendError(
                            'Erreur lors de l\'envoi du SMS d\'activation',
                            ['sms_error' => $smsResult['error']],
                            500
                        );
                    }
                } else {
                    DB::rollBack();
                    return ApiResponseClass::sendError(
                        'Erreur lors de l\'envoi du SMS d\'activation',
                        ['sms_error' => $smsResult['error']],
                        500
                    );
                }
            }

            // 🔑 Connexion auto après inscription
            $token = Auth::login($user);

            DB::commit();
            return ApiResponseClass::created([
                'user' => new UserResource($user),
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => Auth::factory()->getTTL() * 60
            ], $activationVia === 'email'
                ? 'Utilisateur créé avec succès. SMS indisponible — code d\'activation envoyé par email.'
                : 'Utilisateur créé avec succès. SMS d\'activation envoyé.');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de l'utilisateur");
        }
    }

    /**
     * 🔹 Connexion (par email ou téléphone)
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'login' => 'required|string',
                'password' => 'required|string',
            ]);

            $loginInput = $request->input('login');
            $password = $request->input('password');

            $user = $this->userRepository->findByEmail($loginInput)
                ?? $this->userRepository->findByPhone($loginInput);

            if (!$user || !Hash::check($password, $user->password)) {
                return ApiResponseClass::unauthorized('Les identifiants de connexion ne sont pas valides');
            }

            // ⚠️ Vérifie activation
            if (!$user->isActivated()) {
                return ApiResponseClass::sendError('Compte non activé. Vérifiez votre téléphone.', null, 403);
            }

            // 🔒 Si 2FA activé → envoie OTP par SMS
            if ($user->hasTwoFactorEnabled()) {
                $user->generateTwoFactorCode();

                // Envoi SMS 2FA
                $smsResult = $this->smsService->sendSingleSms(
                    'MDING',
                    $user->phone,
                    "Votre code de vérification EDGPAY-MDING est : {$user->two_factor_token}. Ce code expire dans 10 minutes."
                );

                if (!$smsResult['success']) {
                    return ApiResponseClass::sendError(
                        'Erreur lors de l\'envoi du code de vérification',
                        ['sms_error' => $smsResult['error']],
                        500
                    );
                }

                return ApiResponseClass::sendResponse([
                    'user_id' => $user->id,
                    'two_factor_required' => true,
                ], 'Code de vérification requis - SMS envoyé', 202);
            }

            // ✅ Génère JWT
            if (! $token = Auth::login($user)) {
                return ApiResponseClass::unauthorized('Connexion impossible');
            }

            return ApiResponseClass::sendResponse($this->respondWithToken($token, new UserResource($user)), 'Connexion réussie');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la connexion');
        }
    }

    /**
     * 🔹 Vérification du code OTP 2FA
     */
    public function verifyTwoFactor(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'otp' => 'required|string|size:6',
            ]);

            $user = $this->userRepository->getByID($request->user_id);

            if (!$user) {
                return ApiResponseClass::notFound('Utilisateur non trouvé');
            }

            if (!$user->validateTwoFactorCode($request->otp)) {
                return ApiResponseClass::sendError('Code invalide ou expiré', null, 422);
            }

            // ✅ Authentification après validation OTP
            $user->resetTwoFactorCode();
            $token = Auth::login($user);

            return ApiResponseClass::sendResponse($this->respondWithToken($token, $user), 'Connexion réussie');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la vérification du code');
        }
    }

    // ✅ Active l'authentification à deux facteurs pour l'utilisateur connecté
    public function enableTwoFactor(Request $request)
    {
        try {
            $user = $request->user();
            $user->enableTwoFactor();

            return ApiResponseClass::sendResponse($user, 'Authentification à deux facteurs activée avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de l\'activation de la 2FA');
        }
    }

    // ✅ Désactive l'authentification à deux facteurs pour l'utilisateur connecté
    public function disableTwoFactor(Request $request)
    {
        try {
            $user = $request->user();
            $user->disableTwoFactor();

            return ApiResponseClass::sendResponse($user, 'Authentification à deux facteurs désactivée avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la désactivation de la 2FA');
        }
    }

    // ✅ Permet de régénérer et renvoyer un code de vérification 2FA
    public function resendTwoFactorCode(Request $request)
    {
        try {
            $request->validate(['user_id' => 'required|exists:users,id']);

            $user = $this->userRepository->getByID($request->user_id);
            if (!$user) {
                return ApiResponseClass::notFound('Utilisateur non trouvé');
            }

            // ✅ Génère un nouveau code de vérification
            $user->generateTwoFactorCode();

            // ✅ Envoie le code par SMS
            $smsResult = $this->smsService->sendSingleSms(
                'MDING',
                $user->phone,
                "Votre nouveau code de vérification EDGPAY-MDING est : {$user->two_factor_token}. Ce code expire dans 10 minutes."
            );

            if (!$smsResult['success']) {
                return ApiResponseClass::sendError(
                    'Erreur lors de l\'envoi du code',
                    ['sms_error' => $smsResult['error']],
                    500
                );
            }

            return ApiResponseClass::sendResponse(
                ['user_id' => $user->id],
                'Nouveau code de vérification envoyé par SMS'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de l\'envoi du code');
        }
    }

    /**
     * 🔹 Déconnexion → invalide le JWT
     */
    public function logout()
    {
        try {
            Auth::logout();
            return ApiResponseClass::sendResponse([], 'Déconnexion réussie');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la déconnexion');
        }
    }

    /**
     * 🔹 Rafraîchir le JWT
     */
    public function refresh()
    {
        try {
            $token = Auth::refresh();
            return ApiResponseClass::sendResponse($this->respondWithToken($token), 'Token rafraîchi avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors du rafraîchissement du token');
        }
    }

    /**
     * 🔹 Récupération du profil utilisateur connecté
     */
    public function userProfile()
    {
        $user = Auth::user();
        return ApiResponseClass::sendResponse(new UserResource($user), 'Profil utilisateur');
    }

    /**
     * 🔹 Mot de passe oublié
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            // Recherche par téléphone au lieu d'email
            $user = $this->userRepository->findByPhone($request->phone);

            if (!$user) {
                return ApiResponseClass::notFound('Aucun utilisateur avec ce numéro de téléphone');
            }

            // Génère un token de reset + envoi SMS
            $resetToken = $user->generatePasswordResetToken();

            $smsResult = $this->smsService->sendSingleSms(
                'MDING',
                $user->phone,
                "Votre code de réinitialisation EDGPAY-MDING est : {$user->otp}. Ce code expire dans 1 heure."
            );

            if (!$smsResult['success']) {
                // Fallback email si l'utilisateur a une adresse email
                if ($user->email) {
                    try {
                        Mail::to($user->email)->send(new ResetPasswordMail($user));
                        return ApiResponseClass::sendResponse(
                            ['reset_token' => $resetToken, 'via' => 'email'],
                            'SMS indisponible — code de réinitialisation envoyé par email'
                        );
                    } catch (\Throwable $mailEx) {
                        \Log::error('Fallback email reset échoué', ['error' => $mailEx->getMessage(), 'user_id' => $user->id]);
                    }
                }
                return ApiResponseClass::sendError(
                    'Erreur lors de l\'envoi du SMS de réinitialisation',
                    ['sms_error' => $smsResult['error']],
                    500
                );
            }

            return ApiResponseClass::sendResponse(
                ['reset_token' => $resetToken, 'via' => 'sms'],
                'SMS de réinitialisation envoyé'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la demande de réinitialisation');
        }
    }

    /**
     * 🔹 Mot de passe oublié
     */
    public function forgotPasswordEmail(ForgotPasswordEmailRequest $request)
    {
        try {
            $user = $this->userRepository->findByEmail($request->email);

            if (!$user) {
                return ApiResponseClass::notFound('Aucun utilisateur avec cet email');
            }
            // Génère un token de reset + envoi email
            $resetToken = $user->generatePasswordResetToken();
            Mail::to($user->email)->queue(new ResetPasswordMail($user));

            return ApiResponseClass::sendResponse(['reset_token' => $resetToken], 'Email de réinitialisation envoyé');
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la demande de réinitialisation');
        }
    }

    /**
     * 🔹 Réinitialisation du mot de passe avec OTP
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            $user = $this->userRepository->findByOtp($validated['otp']);

            if (!$user) {
                return ApiResponseClass::notFound('Code invalide');
            }

            if (!$user->isPasswordResetTokenValid($validated['otp'])) {
                return ApiResponseClass::sendError('Code expiré ou invalide', null, 422);
            }

            $this->userRepository->updatePassword($user->id, ['password' => $validated['password']]);
            $user->resetPasswordResetToken();

            DB::commit();

            return ApiResponseClass::sendResponse([], 'Mot de passe réinitialisé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la réinitialisation");
        }
    }

    /**
     * 🔹 Vérification du token d'activation (SMS)
     */
    public function verifyAccount(VerifyEmailRequest $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            $user = $this->userRepository->findByOtp($validated['otp']);

            if (!$user) {
                return ApiResponseClass::notFound('Code invalide');
            }

            if (!$user->isActivationAccountTokenValid($validated['otp'])) {
                return ApiResponseClass::sendError('Code expiré ou invalide', null, 422);
            }

            // ✅ Active le compte
            $this->userRepository->update($user->id, [
                'status' => true,
                'email_verified_at' => now(),
                'otp' => null,
                'activation_token' => null,
                'activation_account_expires_at' => null
            ]);

            DB::commit();

            return ApiResponseClass::sendResponse([], 'Compte activé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de l'activation du compte");
        }
    }

    /**
     * 🔹 Renvoyer un code d'activation par SMS
     */
    public function resendVerificationSms(Request $request)
    {
        try {
            $request->validate(['phone' => 'required|string']);

            $user = $this->userRepository->findByPhone($request->phone);

            if (!$user) {
                return ApiResponseClass::sendError('Utilisateur introuvable', null, 404);
            }

            if ($user->status == true) {
                return ApiResponseClass::sendError('Compte déjà activé', null, 422);
            }

            $activationToken = $user->generateActivationToken();

            // Envoi SMS d'activation
            $smsResult = $this->smsService->sendSingleSms(
                'MDING',
                $user->phone,
                "Votre code d'activation EDGPAY-MDING est : {$user->otp}. Utilisez-le pour activer votre compte."
            );

            if (!$smsResult['success']) {
                // Fallback email si SMS échoue
                if ($user->email) {
                    try {
                        Mail::to($user->email)->send(new ActivationMail($user));
                        return ApiResponseClass::sendResponse(
                            ['activation_token' => $activationToken, 'via' => 'email'],
                            'SMS indisponible — code d\'activation envoyé par email'
                        );
                    } catch (\Throwable $mailEx) {
                        Log::error('Fallback email activation échoué (resend)', ['error' => $mailEx->getMessage(), 'user_id' => $user->id]);
                    }
                }
                return ApiResponseClass::sendError(
                    'Erreur lors de l\'envoi du SMS d\'activation',
                    ['sms_error' => $smsResult['error']],
                    500
                );
            }

            return ApiResponseClass::sendResponse(
                ['activation_token' => $activationToken, 'via' => 'sms'],
                'SMS d\'activation renvoyé'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi du SMS: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur interne, réessayez plus tard');
        }
    }


     /**
     * 🔹 Renvoyer un email de vérification
     */
    public function resendVerificationEmail(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);
            $user = $this->userRepository->findByEmail($request->email);

            if (!$user) {
                return ApiResponseClass::sendError('Utilisateur introuvable', null, 404);
            }

            if ($user->status == true && $user->email_verified_at) {
                return ApiResponseClass::sendError('Email déjà vérifié', null, 422);
            }

            $activationToken = $user->generateActivationToken();
            Mail::to($user->email)->queue(new ActivationMail($user, $activationToken));

            return ApiResponseClass::sendResponse(['activation_token' => $activationToken], 'Email de vérification renvoyé');
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'email: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur interne, réessayez plus tard');
        }
    }

    protected function respondWithToken($token, $user = null)
    {
        return [
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => Auth::factory()->getTTL() * 60,
            'user'         => $user ?? Auth::user()
        ];
    }

    public function unauthenticated(): JsonResponse
    {
        try {
            return ApiResponseClass::unauthorized(
                "Non authentifié. Veuillez vous connecter pour accéder à cette ressource.",
                Response::HTTP_UNAUTHORIZED,
                null,
                401
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError($e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
