<?php
// Fichier: routes/api.php

use App\Http\Controllers\API\AnnouncementController;
use App\Http\Controllers\API\CreanceController;
use App\Http\Controllers\API\RiskDashboardController;
use App\Http\Controllers\API\ApiClientController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CommissionController;
use App\Http\Controllers\API\CompteurController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\DemandeProController;
use App\Http\Controllers\API\DmlController;
use App\Http\Controllers\API\DjomyController;
use App\Http\Controllers\API\MessageController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\PaymentLinkController;
use App\Http\Controllers\API\RoleController;
use App\Http\Controllers\API\SmsController;
use App\Http\Controllers\API\SystemSettingController;
use App\Http\Controllers\API\TopupRequestController;
use App\Http\Controllers\API\WalletController;
use App\Http\Controllers\API\WalletTransactionController;
use App\Http\Controllers\API\WithdrawalRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Pusher\Pusher;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes publiques et protégées par Sanctum
|
*/

// ----------------------
// Routes publiques
// ----------------------

Route::prefix('v1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('client/token', [ApiClientController::class, 'tokenClient']);
    // Route::post('client/token', [ApiClientController::class, 'token']);
    // Public: maintenance status (used by mobile/web app before login)
    Route::get('maintenance', [SystemSettingController::class, 'maintenanceStatus']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('forgot-password-email', [AuthController::class, 'forgotPasswordEmail']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('verify-two-factor', [AuthController::class, 'verifyTwoFactor']);
    Route::post('resend-two-factor-code', [AuthController::class, 'resendTwoFactorCode']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::post('verify-account', [AuthController::class, 'verifyAccount']);
    Route::post('resend-verification-phone', [AuthController::class, 'resendVerificationSms']);
    Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail']);
    Route::post('users', [UserController::class, 'store']); // 🆕 Création utilisateur
});

// ----------------------
// Routes protégées par JWT
// ----------------------
Route::prefix('v1')->middleware('auth:api')->group(function () {
    // FINTECH (admin): approbation commande avec statut_paiement obligatoire
    Route::post('admin/commandes/{id}/approuver', [TopupRequestController::class, 'approve']);


    // Auth
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh-token', [AuthController::class, 'refresh']);
    Route::get('profile', [AuthController::class, 'userProfile']);
    Route::put('profile', [AuthController::class, 'updateProfile']);
    Route::post('enable-two-factor', [AuthController::class, 'enableTwoFactor']);
    Route::post('disable-two-factor', [AuthController::class, 'disableTwoFactor']);


    //ROLES
    Route::get('roles', [RoleController::class, 'index']);
    Route::get('roles/{id}', [RoleController::class, 'show']);
    Route::get('all-permissions', [RoleController::class, 'getAllPermissions']);
    Route::put('roles/{id}/permissions', [RoleController::class, 'updatePermissions']);
    Route::put('roles/{id}/permissions/detach', [RoleController::class, 'detachPermissions']);


    // Users
    Route::get('users', [UserController::class, 'index']); // 📋 Liste des utilisateurs
    Route::get('users/assigned', [UserController::class, 'getUsersByAssigned']); // 📋 Liste des utilisateurs
    Route::get('roles', [UserController::class, 'getAllRoles']); // 📋 Liste des roles
    Route::get('users/{id}', [UserController::class, 'show']); // 📄 Détails utilisateur

    Route::put('users/{id}', [UserController::class, 'update']); // ✏️ Mise à jour utilisateur
    Route::put('users/{id}/password', [UserController::class, 'updatePassword']); // 🔐 Mise à jour mot de passe
    Route::put('users/{id}/password-for-user', [UserController::class, 'updatePasswordForUser']); // 🔐 Mise à jour mot de passe
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
    Route::delete('users/{id}', [UserController::class, 'destroy']); // ❌ Suppression utilisateur



    // 📥 Liste des demandes (optionnel: ?status=accepté)
    Route::get('demandes-pro', [DemandeProController::class, 'index']);

    // 📄 Détail d'une demande
    Route::get('demandes-pro/{id}', [DemandeProController::class, 'show']);
    Route::get('demandes-pro/{id}/user', [DemandeProController::class, 'findByUser']);
    Route::get('/trashed', [DemandeProController::class, 'trashed']); // Demandes supprimées
    Route::get('/cancelled', [DemandeProController::class, 'cancelled']); // Demandes annulées

    // 🆕 Création
    Route::post('demandes-pro', [DemandeProController::class, 'store']);

    // ✏️ Mise à jour complète
    Route::put('demandes-pro/{id}', [DemandeProController::class, 'update']);

    // 🔄 Mise à jour du statut uniquement
    Route::patch('demandes-pro/{id}/status', [DemandeProController::class, 'updateStatus']);

    // ❌ Suppression
    Route::post('demandes-pro/{id}/cancel', [DemandeProController::class, 'cancel']); // Annulation
    Route::post('demandes-pro/{id}/restore', [DemandeProController::class, 'restore']); // Restauration
    Route::delete('demandes-pro/{id}/force', [DemandeProController::class, 'forceDelete']); // Suppression 
    Route::delete('demandes-pro/{id}', [DemandeProController::class, 'destroy']);



    // CRUD classique
    Route::get('wallets', [WalletController::class, 'index']);            // Liste des wallets
    Route::post('wallets', [WalletController::class, 'store']);           // Créer un wallet
    Route::get('wallets/{id}', [WalletController::class, 'show']);         // Afficher un wallet
    Route::get('wallets/{userId}/user', [WalletController::class, 'getByUserId']);         // Afficher un wallet
    Route::get('wallets/{walletId}/float', [WalletController::class, 'showFloat']); ///api/wallets/{walletId}/float?provider=EDG

    Route::put('wallets/{id}', [WalletController::class, 'update']);       // Mettre à jour un wallet
    Route::delete('wallets/{id}', [WalletController::class, 'destroy']);   // Supprimer un wallet

    Route::patch('wallets/{id}/float-rate', [WalletController::class, 'updateFloatRate']);
    // Opérations financières via WalletRepository (simple mise à jour)
    Route::patch('wallets/{id}/balance', [WalletController::class, 'updateBalance']);    // Modifier solde
    Route::patch('wallets/{id}/commission', [WalletController::class, 'addCommission']); // Ajouter commission

    // Opérations financières via WalletService (transaction + journalisation)
    Route::post('wallets/{id}/deposit', [WalletController::class, 'deposit']);            // Dépôt
    Route::post('wallets/recharge/superadmin', [WalletController::class, 'rechargeSuperAdmin']);            // Dépôt
    Route::post('wallets/{id}/withdraw', [WalletController::class, 'withdraw']);          // Retrait
    Route::post('wallets/{id}/commission', [WalletController::class, 'addCommissionService']); // Ajouter commission via service
    Route::post('wallets/{id}/transfer-commission', [WalletController::class, 'transferCommission']); // Ajouter commission via service
    Route::get('wallets/{userId}/stats', [WalletController::class, 'getUserStats']);
    // Nouvelles routes pour les transferts entre floats
    Route::post('wallets/{walletId}/transfer-between-floats', [WalletController::class, 'transferBetweenFloats']);
    Route::post('wallets/{walletId}/transfer-between-providers', [WalletController::class, 'transferBetweenProviders']);
    Route::get('wallets/commissions/summary', [WalletController::class, 'getCommissionSummary']);


    // Nouvelles routes pour la gestion cohérente des soldes
    Route::prefix('balance')->group(function () {
        Route::get('total-cash', [WalletController::class, 'getTotalCash']);
        // Récupérer le solde cohérent pour un provider
        Route::get('/consistent', [WalletController::class, 'getConsistentBalance']);
        // Récupérer le solde disponible pour un provider
        Route::get('/available', [WalletController::class, 'getAvailableBalance']);
        // Vérifier si le solde est suffisant
        Route::post('/check-sufficient', [WalletController::class, 'checkSufficientBalance']);
        // Récupérer tous les soldes (tous providers)
        Route::get('/all', [WalletController::class, 'getAllBalances']);
        // Récupérer spécifiquement le solde EDG Pro
        Route::get('/edg-pro', [WalletController::class, 'getEdgProBalance']);
    });


    // Routes pour les demandes de retrait
    Route::prefix('withdrawal-requests')->group(function () {
        // Création et listing
        Route::post('/', [WithdrawalRequestController::class, 'store']);
        Route::post('/secured', [WithdrawalRequestController::class, 'storeSecured']);
        Route::get('/', [WithdrawalRequestController::class, 'index']);

        // Actions sur une demande spécifique
        Route::get('/{id}', [WithdrawalRequestController::class, 'show']);
        Route::post('/{id}/approve', [WithdrawalRequestController::class, 'approve']);
        Route::post('/{id}/reject', [WithdrawalRequestController::class, 'reject']);
        Route::post('/{id}/cancel', [WithdrawalRequestController::class, 'cancel']);

        // Statistiques
        Route::get('/stats/overview', [WithdrawalRequestController::class, 'getStats']);
        Route::get('/stats/user/{userId}', [WithdrawalRequestController::class, 'getUserStats']);
        Route::get('/stats/daily', [WithdrawalRequestController::class, 'getDailyStats']);

        // Historique utilisateur
        Route::get('/user/{userId}/history', [WithdrawalRequestController::class, 'getUserHistory']);
    });


    // 📌 Transactions Wallet
    // 📥 Liste des transactions
    Route::get('wallet-transactions', [WalletTransactionController::class, 'index']);

    // 📄 Détail d’une transaction
    Route::get('wallet-transactions/{id}', [WalletTransactionController::class, 'show']);

    // 🆕 Création d’une transaction
    Route::post('wallet-transactions', [WalletTransactionController::class, 'store']);

    // ✏️ Mise à jour d’une transaction
    Route::put('wallet-transactions/{id}', [WalletTransactionController::class, 'update']);

    // ❌ Suppression d’une transaction
    Route::delete('wallet-transactions/{id}', [WalletTransactionController::class, 'destroy']);

    // 📥 Liste des transactions par user
    Route::get('wallet-transactions/{id}/user', [WalletTransactionController::class, 'findByUser']);




    Route::prefix('topup-requests')->group(function () {
        // Routes accessibles à tous les utilisateurs authentifiés
        Route::get('/', [TopupRequestController::class, 'index']);
        Route::post('/', [TopupRequestController::class, 'store']);
        Route::get('/user/{userId}', [TopupRequestController::class, 'findByUser']);
        Route::get('/user/where/{userId}', [TopupRequestController::class, 'findByUserWhere']);
        Route::get('/status/{status}', [TopupRequestController::class, 'findByStatus']);
        Route::get('/status/{status}/{proId}', [TopupRequestController::class, 'findByStatusAndPro']);
        Route::get('/statistics', [TopupRequestController::class, 'statistics']);
        // Endpoint pour récupérer les recharges des pros pour le sous-admin connecté
        Route::get('/subadmin/recharges', [TopupRequestController::class, 'getRechargesProForSubAdmin']);

        // Routes pour une demande spécifique
        Route::prefix('{id}')->group(function () {
            Route::get('/', [TopupRequestController::class, 'show']);
            Route::put('/', [TopupRequestController::class, 'update']);
            Route::delete('/', [TopupRequestController::class, 'destroy']);
            Route::post('/cancel', [TopupRequestController::class, 'cancel']);

            // Routes administrateur uniquement
            // Route::middleware(['admin'])->group(function () {

            // });

            Route::put('/status', [TopupRequestController::class, 'updateStatus']);
            Route::post('/approve', [TopupRequestController::class, 'approve']);
            Route::post('/reject', [TopupRequestController::class, 'reject']);
        });
    });

    Route::prefix('compteurs')->group(function () {
        // 📥 Liste des compteurs
        Route::get('/', [CompteurController::class, 'index']);

        // 📄 Détail d’un compteur
        Route::get('/{id}', [CompteurController::class, 'show']);

        // 🆕 Création
        Route::post('/', [CompteurController::class, 'store']);

        // ✏️ Mise à jour
        Route::put('/{id}', [CompteurController::class, 'update']);
        Route::patch('/{id}', [CompteurController::class, 'update']);

        // ❌ Suppression
        Route::delete('/{id}', [CompteurController::class, 'destroy']);

        // 🔍 Filtrer par type
        Route::get('/type/{type}', [CompteurController::class, 'findByType']);
        Route::get('/client/{clientId}', [CompteurController::class, 'findByClient']);

        Route::post('/check-existence', [CompteurController::class, 'checkCompteur']);
    });


    // Routes DML
    Route::prefix('dml')->group(function () {
        Route::post('login', [DmlController::class, 'login']);
        Route::post('/prepaid/search-customer', [DmlController::class, 'searchPrepaidCustomer']);
        Route::post('/prepaid/save-transaction', [DmlController::class, 'savePrepaidTransaction']);
        Route::post('/postpayment/search-customer', [DmlController::class, 'searchPostPaymentCustomer']);
        Route::post('/postpayment/save-transaction', [DmlController::class, 'savePostPaymentTransaction']);
        Route::post('/postpayment/get-transaction', [DmlController::class, 'getTransaction']);
        Route::get('/get-balance', [DmlController::class, 'getBalance']);
        Route::get('/transaction-history', [DmlController::class, 'getTransactionHistory']);
        Route::get('/transaction-history-admin', [DmlController::class, 'getTransactionHistoryAdmin']);



        Route::post('/client/prepaid/search-customer', [DmlController::class, 'searchPrepaidCustomer']);
        Route::post('/client/prepaid/save-transaction', [DmlController::class, 'savePrepaidTransaction']);
        Route::post('/client/postpayment/search-customer', [DmlController::class, 'searchPostPaymentCustomer']);
        Route::post('/client/postpayment/save-transaction', [DmlController::class, 'savePostPaymentTransaction']);
    });


    Route::prefix('djomy')->group(function () {
        // Payments Djomy
        Route::prefix('payments')->group(function () {
            Route::post('/direct', [DjomyController::class, 'createPayment']);
            Route::post('/gateway', [DjomyController::class, 'createPaymentWithGateway']);
            Route::get('/{paymentId}/status', [DjomyController::class, 'getPaymentStatus']);
            Route::get('/{paymentId}/links/status', [DjomyController::class, 'getPaymentLinkStatus']);
            Route::get('/health', [DjomyController::class, 'healthCheck']);
            Route::post('/status', [DjomyController::class, 'status']);
            Route::post('/cancel', [DjomyController::class, 'cancel']);
        });

        // Links Djomy
        Route::prefix('links')->group(function () {
            Route::post('/', [DjomyController::class, 'generateLink']);
            Route::get('/', [DjomyController::class, 'getLinks']);
            Route::get('/{linkId}', [DjomyController::class, 'getLink']);
        });

        // Auth Djomy
        Route::post('/auth', [DjomyController::class, 'authenticate']);
    });

    Route::prefix('payments')->group(function () {
        // Routes CRUD locales
        Route::get('/', [PaymentController::class, 'index']);
        Route::get('/stats', [PaymentController::class, 'stats']);
        Route::get('/total-amount', [PaymentController::class, 'totalAmount']);
        Route::get('/failed-for-retry', [PaymentController::class, 'failedPaymentsForRetry']);
        Route::get('/count/status/{status}', [PaymentController::class, 'countByStatus']);
        Route::get('/count/method/{paymentMethod}', [PaymentController::class, 'countByPaymentMethod']);
        Route::get('/check-reference/{merchantReference}', [PaymentController::class, 'checkMerchantReference']);

        Route::post('/', [PaymentController::class, 'store']);
        Route::get('/{id}', [PaymentController::class, 'show']);
        Route::put('/{id}', [PaymentController::class, 'update']);
        Route::delete('/{id}', [PaymentController::class, 'destroy']);
        Route::patch('/{id}/restore', [PaymentController::class, 'restore']);
        Route::delete('/{id}/force', [PaymentController::class, 'forceDelete']);

        // Routes Djomy
        Route::post('/direct', [DjomyController::class, 'createPayment']);
        Route::post('/gateway', [DjomyController::class, 'createPaymentWithGateway']);
        Route::get('/{paymentId}/status', [DjomyController::class, 'getPaymentStatus']);
        Route::post('/links', [DjomyController::class, 'generateLink']);
        Route::get('/health/check', [DjomyController::class, 'healthCheck']);
    });

    Route::prefix('payment-links')->group(function () {
        // 📋 Lister tous les liens de paiement
        Route::get('/', [PaymentLinkController::class, 'index']);

        // 🔍 Afficher un lien de paiement par ID
        Route::get('/{id}', [PaymentLinkController::class, 'show']);

        // 🔄 Vérifier le statut d'un lien de paiement par external_link_id
        Route::get('/status/{externalLinkId}', [PaymentLinkController::class, 'status']);
    });


    // Routes pour la gestion des clients API (protégées par auth user normal)
    Route::prefix('api-clients')->group(function () {
        Route::post('/', [ApiClientController::class, 'createClient']);
        Route::post('/user', [ApiClientController::class, 'createClientWithToken']);
        Route::get('/', [ApiClientController::class, 'listClients']);
        Route::delete('/{clientId}', [ApiClientController::class, 'revokeClient']);
    });


    Route::prefix('sms')->group(function () {
        Route::post('/send', [SmsController::class, 'sendSms']);
        Route::post('/send-quick', [SmsController::class, 'sendQuickSms']);
        Route::get('/messages', [SmsController::class, 'getMessages']);
        Route::get('/messages/{messageId}', [SmsController::class, 'getMessageDetails']);
    });




    // Paramètres système
    Route::prefix('system-settings')->group(function () {
        // Récupérer tous les paramètres
        Route::get('/', [SystemSettingController::class, 'index']);

        // Récupérer les paramètres de paiement
        Route::get('/payments', [SystemSettingController::class, 'getPaymentSettings']);

        // Vérifier si les paiements sont activés pour un type d'utilisateur
        Route::get('/check-payment-enabled', [SystemSettingController::class, 'checkPaymentEnabled']);

        // Récupérer un paramètre par clé
        Route::get('/key/{key}', [SystemSettingController::class, 'showByKey']);

        // Mettre à jour un paramètre par clé
        Route::put('/key/{key}', [SystemSettingController::class, 'updateSetting']);

        // Mettre à jour plusieurs paramètres
        Route::put('/bulk-update', [SystemSettingController::class, 'updateMultipleSettings']);

        // CRUD standard
        Route::get('/{id}', [SystemSettingController::class, 'show']);
        Route::delete('/{id}', [SystemSettingController::class, 'destroy']);
    });


    // Routes pour les commissions
    Route::prefix('commissions')->group(function () {
        Route::get('/', [CommissionController::class, 'index']);
        Route::post('/', [CommissionController::class, 'store']);
        Route::put('/multiple', [CommissionController::class, 'updateMultiple']);

        Route::prefix('{id}')->group(function () {
            Route::get('/', [CommissionController::class, 'show']);
            Route::put('/', [CommissionController::class, 'update']);
            Route::delete('/', [CommissionController::class, 'destroy']);
        });

        Route::prefix('key/{key}')->group(function () {
            Route::get('/', [CommissionController::class, 'showByKey']);
            Route::put('/', [CommissionController::class, 'updateByKey']);
            Route::delete('/', [CommissionController::class, 'destroyByKey']);
        });
    });


    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::get('/stats', [AnnouncementController::class, 'stats']);
        Route::get('/{id}', [AnnouncementController::class, 'show']);
        Route::post('/mark-all-read', [AnnouncementController::class, 'markAllAsRead']);
        Route::post('/{id}/mark-read', [AnnouncementController::class, 'markAsRead']);
        Route::delete('/{id}', [AnnouncementController::class, 'destroy']);
    });


    // Messages
    Route::get('/messages/users', [MessageController::class, 'getUsers']);
    Route::get('/messages/conversation/{userId}', [MessageController::class, 'getConversation']);
    Route::get('/messages/conversations', [MessageController::class, 'getAllConversations']);
    Route::post('/messages/send', [MessageController::class, 'sendMessage']);
    Route::put('/messages/{messageId}/read', [MessageController::class, 'markAsRead']);
    Route::put('/messages/conversation/{userId}/read-all', [MessageController::class, 'markConversationAsRead']);
    Route::get('/messages/unread-count', [MessageController::class, 'getUnreadCount']);
    Route::get('/messages/recent-conversations', [MessageController::class, 'getRecentConversations']);
});



Route::prefix('v1')->middleware('auth:api-user')->group(function () {
    Route::prefix('dml-external')->group(function () {
        Route::post('/client/prepaid/search-customer', [DmlController::class, 'searchPrepaidCustomer']);
        Route::post('/client/prepaid/save-transaction', [DmlController::class, 'savePrepaidTransaction']);
        Route::post('/client/postpayment/search-customer', [DmlController::class, 'searchPostPaymentCustomer']);
        Route::post('/client/postpayment/save-transaction', [DmlController::class, 'savePostPaymentTransaction']);
    });

    Route::prefix('payment-external')->group(function () {
        Route::post('/gateway', [DjomyController::class, 'createPaymentWithGatewayEcommerce']);
        Route::get('/{paymentId}/status', [DjomyController::class, 'getPaymentStatusEcommerce']);
    });
});



// routes/api.php
Route::middleware('auth:api')->group(function () {
    // Route d'authentification Pusher
    Route::post('/broadcasting/auth', function (Request $request) {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        // Log pour débogage
        Log::info('Pusher auth attempt', [
            'user_id' => $user->id,
            'socket_id' => $socketId,
            'channel_name' => $channelName,
        ]);

        // Vérifier les permissions du canal
        if (str_starts_with($channelName, 'private-')) {
            // Canal privé : vérifier que l'utilisateur peut y accéder
            // Par exemple, pour private-chat.{userId}
            if (str_starts_with($channelName, 'private-chat.')) {
                $channelUserId = str_replace('private-chat.', '', $channelName);
                if ($user->id != $channelUserId) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }
        }

        // Générer l'authentification
        $pusher = new Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            config('broadcasting.connections.pusher.options')
        );

        $auth = $pusher->socket_auth($channelName, $socketId);

        return response()->json(json_decode($auth));
    });
});

// =============================================================================
//  MODULE CRÉDIT PRO — Quasi Bancaire
// =============================================================================

// Pièce jointe (preuve) d'un remboursement — admin OU propriétaire de la transaction
Route::prefix('v1')->middleware(['auth:api'])->group(function () {
    Route::get('creances/transactions/{id}/preuve', [CreanceController::class, 'preuveTransaction']);
});

// ─── Routes CLIENT (PRO authentifié) ────────────────────────────────────────
Route::prefix('v1')->middleware(['auth:api', 'credit.profile'])->group(function () {

    // Mes créances
    Route::get('mes-creances', [CreanceController::class, 'mesCreances']);

    // Historique transactions (client)
    Route::get('mes-creances/transactions', [CreanceController::class, 'mesTransactions']);

    // Détail d'une créance (client)
    Route::get('mes-creances/{id}', [CreanceController::class, 'mesCreanceDetail']);

    // Soumettre un remboursement (anti-replay + throttle dédié)
    Route::post(
        'creances/{id}/payer',
        [CreanceController::class, 'soumettrePaiement']
    )->middleware(['anti.replay:paiement,300', 'throttle:10,1']);
});

// ─── Routes ADMIN créances ───────────────────────────────────────────────────
Route::prefix('v1')->middleware(['auth:api', 'check-permission:credits.manage'])->group(function () {

    // CRUD créances
    Route::get('creances',        [CreanceController::class, 'index']);
    Route::post('creances',       [CreanceController::class, 'store']);
    Route::get('creances/{id}',   [CreanceController::class, 'show']);

    // Paiements en attente
    Route::get('creances/transactions/en-attente', [CreanceController::class, 'transactionsEnAttente']);

    // Historique des paiements validés
    Route::get('creances/transactions/validees', [CreanceController::class, 'transactionsValidees']);

    // Validation / rejet paiement (throttle strict anti-double-clic)
    Route::post('creances/transactions/{id}/valider',
        [CreanceController::class, 'validerPaiement']
    )->middleware('throttle:30,1');

    Route::post('creances/transactions/{id}/rejeter',
        [CreanceController::class, 'rejeterPaiement']
    )->middleware('throttle:30,1');

    // Gestion limite et blocage
    Route::put('credit/clients/{userId}/limite',    [CreanceController::class, 'modifierLimite']);
    Route::post('credit/clients/{userId}/bloquer',  [CreanceController::class, 'bloquerCompte']);
    Route::post('credit/clients/{userId}/debloquer',[CreanceController::class, 'debloquerCompte']);
});

// ─── Routes RISK DASHBOARD (admin) ──────────────────────────────────────────
Route::prefix('v1/risk')->middleware(['auth:api', 'check-permission:credits.manage'])->group(function () {

    // Vue d'ensemble
    Route::get('dashboard',         [RiskDashboardController::class, 'overview']);
    Route::get('distribution',      [RiskDashboardController::class, 'distributionScores']);
    Route::get('top-clients',       [RiskDashboardController::class, 'topClients']);
    Route::get('clients-risque',    [RiskDashboardController::class, 'clientsARisque']);

    // Anomalies
    Route::get('anomalies',                      [RiskDashboardController::class, 'anomalies']);
    Route::post('anomalies/{id}/resoudre',       [RiskDashboardController::class, 'resoudreAnomalie']);

    // Profil client
    Route::get('clients/{userId}/profil',        [RiskDashboardController::class, 'profilClient']);
    Route::post('clients/{userId}/recalculer-score', [RiskDashboardController::class, 'recalculerScore']);

    // Ledger
    Route::get('clients/{userId}/ledger',        [RiskDashboardController::class, 'ledgerClient']);
    Route::get('clients/{userId}/ledger/integrite', [RiskDashboardController::class, 'verifierIntegriteLedger']);

    // Audit trail
    Route::get('audit',                          [RiskDashboardController::class, 'auditTrail']);
});
