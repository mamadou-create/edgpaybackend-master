<?php

namespace App\Services\WhatsApp;

use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WhatsAppMessageLog;
use App\Models\UserAssistantMemory;
use App\Services\AssistantMemoryService;
use App\Services\NimbaAiAssistantService;
use App\Services\NimbaSmsService;
use App\Services\WalletService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppFintechService
{
    public function __construct(
        private WhatsAppCloudApiService $gateway,
        private WhatsAppConversationStateService $conversationState,
        private WhatsAppIntentParser $intentParser,
        private WalletService $walletService,
        private NimbaSmsService $smsService,
        private AssistantMemoryService $assistantMemoryService,
        private NimbaAiAssistantService $aiAssistantService,
    ) {}

    public function handleWebhook(array $payload): array
    {
        $inbound = $this->gateway->normalizeInboundPayload($payload);
        $phone = $inbound['phone'];
        $replyPhone = (string) ($inbound['reply_phone'] ?? $phone);
        $message = $inbound['message'];

        if ($phone === '' || $message === '') {
            return [
                'success' => false,
                'reply' => 'Payload WhatsApp invalide.',
            ];
        }

        $session = $this->conversationState->getOrCreate($phone);
        $user = $this->findUserByPhone($phone);

        if ($user && $session->user_id !== $user->id) {
            $session->user_id = $user->id;
            $session->save();
        }

        $this->storeMessageLog(
            phone: $phone,
            user: $user,
            direction: 'inbound',
            message: $message,
            sessionId: $session->id,
            intent: null,
            payload: $inbound['raw'],
            providerMessageId: $inbound['provider_message_id'],
            receivedAt: Carbon::parse($inbound['timestamp']),
        );

        $response = $this->processMessage($phone, $message, $user);
        $this->gateway->sendTextMessage($replyPhone, $response['reply']);

        $updatedSession = $this->conversationState->getOrCreate($phone);
        $this->storeMessageLog(
            phone: $phone,
            user: $user,
            direction: 'outbound',
            message: $response['reply'],
            sessionId: $updatedSession->id,
            intent: $response['intent'] ?? null,
            payload: ['response' => $response],
            providerMessageId: null,
            sentAt: now(),
        );

        return $response;
    }

    public function createUserForWhatsApp(string $phone, string $name, string $birthDate, string $pin): array
    {
        $normalizedPhone = $this->normalizePhone($phone);

        if ($this->findUserByPhone($normalizedPhone)) {
            throw new RuntimeException('Un utilisateur existe déjà pour ce numéro WhatsApp.');
        }

        $role = Role::query()->where('slug', RoleEnum::CLIENT->value)->firstOrFail();

        $user = User::query()->create([
            'email' => sprintf('wa_%s@nimba.local', $normalizedPhone),
            'phone' => $normalizedPhone,
            'whatsapp_phone' => $normalizedPhone,
            'whatsapp_verified_at' => now(),
            'phone_verified_at' => now(),
            'display_name' => $name,
            'date_of_birth' => $birthDate,
            'password' => Str::random(32),
            'pin_hash' => Hash::make($pin),
            'role_id' => $role->id,
            'status' => true,
            'is_pro' => false,
        ]);

        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'currency' => config('whatsapp.default_currency', 'GNF'),
            'cash_available' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
            'blocked_amount' => 0,
        ]);

        $session = $this->conversationState->getOrCreate($normalizedPhone);
        $this->conversationState->updateState($session, 'idle', []);

        return [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'phone' => $normalizedPhone,
            'display_name' => $user->display_name,
        ];
    }

    public function linkExistingAccount(string $whatsappPhone, string $accountPhone): array
    {
        $whatsappPhone = $this->normalizePhone($whatsappPhone);
        $accountPhone = $this->normalizePhone($accountPhone);

        $user = User::query()->where('phone', $accountPhone)->first();
        if (!$user) {
            throw new RuntimeException('Aucun compte existant trouvé pour ce numéro.');
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($this->linkOtpCacheKey($whatsappPhone), [
            'code_hash' => Hash::make($otp),
            'user_id' => $user->id,
            'phone' => $accountPhone,
        ], now()->addMinutes(10));

        try {
            $this->smsService->sendSingleSms('NIMBA', $accountPhone, "Votre code OTP NIMBA est : {$otp}");
        } catch (\Throwable $e) {
            Log::warning('whatsapp.link.otp_sms_failed', [
                'phone' => $accountPhone,
                'error' => $e->getMessage(),
            ]);
        }

        $session = $this->conversationState->getOrCreate($whatsappPhone);
        $this->conversationState->updateState($session, 'awaiting_otp', [
            'purpose' => 'link_account',
            'account_phone' => $accountPhone,
            'user_id' => $user->id,
        ]);

        return [
            'otp_sent' => true,
            'whatsapp_phone' => $whatsappPhone,
            'account_phone' => $accountPhone,
            'otp' => $this->shouldExposeOtpForDevelopment() ? $otp : null,
        ];
    }

    public function verifyLinkOtp(string $whatsappPhone, string $code): array
    {
        $whatsappPhone = $this->normalizePhone($whatsappPhone);
        $cached = Cache::get($this->linkOtpCacheKey($whatsappPhone));

        if (!$cached || !Hash::check($code, $cached['code_hash'])) {
            throw new RuntimeException('OTP invalide ou expiré.');
        }

        /** @var User $user */
        $user = User::query()->findOrFail($cached['user_id']);
        $user->forceFill([
            'whatsapp_phone' => $whatsappPhone,
            'whatsapp_verified_at' => now(),
        ])->save();

        Cache::forget($this->linkOtpCacheKey($whatsappPhone));

        $session = $this->conversationState->getOrCreate($whatsappPhone);
        $this->conversationState->updateState($session, 'idle', []);

        return [
            'linked' => true,
            'user_id' => $user->id,
            'display_name' => $user->display_name,
            'whatsapp_phone' => $whatsappPhone,
        ];
    }

    public function getWalletBalance(string $whatsappPhone, string $pin): array
    {
        $user = $this->resolveUserWithPin($whatsappPhone, $pin);
        $wallet = $this->getOrCreateWallet($user);

        return [
            'balance' => (int) ($wallet->cash_available - $wallet->blocked_amount),
            'currency' => $wallet->currency ?? config('whatsapp.default_currency', 'GNF'),
        ];
    }

    public function sendMoney(
        string $whatsappPhone,
        string $receiverPhone,
        int $amount,
        string $pin,
        ?string $otp = null,
    ): array {
        $user = $this->resolveUserWithPin($whatsappPhone, $pin);
        $receiver = $this->findUserByPhone($receiverPhone);

        if (!$receiver) {
            throw new RuntimeException('Destinataire introuvable.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Montant invalide.');
        }

        $limit = (int) config('whatsapp.transaction_limit', 5000000);
        if ($amount > $limit) {
            throw new RuntimeException('Montant supérieur à la limite autorisée.');
        }

        if ($amount >= (int) config('whatsapp.otp_threshold', 100000)) {
            $cacheKey = $this->transferOtpCacheKey($user->id);

            if ($otp === null) {
                $generated = (string) random_int(100000, 999999);
                Cache::put($cacheKey, [
                    'code_hash' => Hash::make($generated),
                    'amount' => $amount,
                    'receiver_id' => $receiver->id,
                ], now()->addMinutes(10));

                try {
                    $this->smsService->sendSingleSms('NIMBA', $user->phone, "Votre OTP NIMBA transfert est : {$generated}");
                } catch (\Throwable $e) {
                    Log::warning('whatsapp.transfer.otp_sms_failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'requires_otp' => true,
                    'message' => 'OTP requis pour confirmer le transfert.',
                ];
            }

            $cached = Cache::get($cacheKey);
            if (!$cached || !Hash::check($otp, $cached['code_hash'])) {
                throw new RuntimeException('OTP transfert invalide ou expiré.');
            }

            Cache::forget($cacheKey);
        }

        $this->executeTransfer($user, $receiver, $amount);
        $wallet = $this->getOrCreateWallet($user);

        return [
            'success' => true,
            'amount' => $amount,
            'receiver_phone' => $receiver->phone,
            'currency' => $wallet->currency ?? config('whatsapp.default_currency', 'GNF'),
        ];
    }

    public function verifyTransferOtp(
        string $whatsappPhone,
        string $receiverPhone,
        int $amount,
        string $otp,
    ): array {
        $user = $this->findUserByPhone($whatsappPhone);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }

        $receiver = $this->findUserByPhone($receiverPhone);
        if (!$receiver) {
            throw new RuntimeException('Destinataire introuvable.');
        }

        $cacheKey = $this->transferOtpCacheKey($user->id);
        $cached = Cache::get($cacheKey);
        if (!$cached || !Hash::check($otp, $cached['code_hash'])) {
            throw new RuntimeException('OTP transfert invalide ou expiré.');
        }

        Cache::forget($cacheKey);
        $this->executeTransfer($user, $receiver, $amount);

        $wallet = $this->getOrCreateWallet($user);

        return [
            'success' => true,
            'amount' => $amount,
            'receiver_phone' => $receiver->phone,
            'currency' => $wallet->currency ?? config('whatsapp.default_currency', 'GNF'),
        ];
    }

    public function getTransactionHistory(string $whatsappPhone, string $pin, int $limit = 5): array
    {
        $user = $this->resolveUserWithPin($whatsappPhone, $pin);

        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(max(1, min($limit, 10)))
            ->get(['id', 'amount', 'type', 'reference', 'description', 'created_at']);

        return [
            'transactions' => $transactions->map(fn (WalletTransaction $transaction) => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'type' => $transaction->type,
                'reference' => $transaction->reference,
                'description' => $transaction->description,
                'created_at' => optional($transaction->created_at)->toIso8601String(),
            ])->all(),
        ];
    }

    public function createSupportTicket(string $phone, string $message, ?string $reason = null): array
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $user = $this->findUserByPhone($normalizedPhone);

        $supportRequest = SupportRequest::query()->create([
            'user_id' => $user?->id,
            'source' => 'whatsapp',
            'reason' => $reason ?? 'user_requested_support',
            'status' => 'open',
            'last_user_message' => $message,
            'transcript' => [
                ['role' => 'user', 'content' => $message, 'timestamp' => now()->toIso8601String()],
            ],
            'metadata' => [
                'phone' => $normalizedPhone,
                'channel' => 'whatsapp',
            ],
            'transferred_at' => now(),
        ]);

        return [
            'support_request_id' => $supportRequest->id,
            'status' => $supportRequest->status,
        ];
    }

    public function processMessage(string $phone, string $message, ?User $user = null): array
    {
        $phone = $this->normalizePhone($phone);
        $session = $this->conversationState->getOrCreate($phone);
        $session->last_message = $message;
        $session->last_interaction_at = now();
        $session->save();

        if ($user === null) {
            $user = $this->findUserByPhone($phone);
        }

        if (in_array(mb_strtolower(trim($message)), ['menu', 'retour menu', '0'], true)) {
            $this->conversationState->updateState($session, 'awaiting_menu_choice', []);
            return $this->welcomeResponse($user !== null);
        }

        if ($user === null) {
            return $this->handleGuestFlow($session, $phone, $message);
        }

        return $this->handleKnownUserFlow($session, $user, $message);
    }

    private function handleGuestFlow($session, string $phone, string $message): array
    {
        $state = $session->state;
        $context = $session->context ?? [];

        if ($state === 'awaiting_account_name') {
            $context['name'] = trim($message);
            $this->conversationState->updateState($session, 'awaiting_birth_date', $context, $message);
            return $this->response('Indiquez votre date de naissance au format YYYY-MM-DD.', 'CREATE_ACCOUNT');
        }

        if ($state === 'awaiting_birth_date') {
            try {
                $date = Carbon::parse($message)->format('Y-m-d');
            } catch (\Throwable) {
                return $this->response('Date invalide. Exemple: 1998-06-15.', 'CREATE_ACCOUNT');
            }

            $context['date_of_birth'] = $date;
            $this->conversationState->updateState($session, 'awaiting_pin', $context, $message);
            return $this->response('Choisissez un PIN de sécurité à 4 ou 6 chiffres.', 'CREATE_ACCOUNT');
        }

        if ($state === 'awaiting_pin') {
            if (!preg_match('/^\d{4,6}$/', trim($message))) {
                return $this->response('PIN invalide. Utilisez 4 à 6 chiffres.', 'CREATE_ACCOUNT');
            }

            $context['pin'] = trim($message);
            $this->conversationState->updateState($session, 'awaiting_pin_confirmation', $context, $message);
            return $this->response('Confirmez votre PIN.', 'CREATE_ACCOUNT');
        }

        if ($state === 'awaiting_pin_confirmation') {
            if (($context['pin'] ?? null) !== trim($message)) {
                $this->conversationState->updateState($session, 'awaiting_pin', Arr::except($context, ['pin']), $message);
                return $this->response('Les PIN ne correspondent pas. Saisissez à nouveau votre PIN.', 'CREATE_ACCOUNT');
            }

            $created = $this->createUserForWhatsApp(
                $phone,
                (string) ($context['name'] ?? 'Client WhatsApp'),
                (string) ($context['date_of_birth'] ?? now()->format('Y-m-d')),
                (string) $context['pin'],
            );

            return $this->response(
                "Compte créé avec succès. Bienvenue {$created['display_name']}.",
                'CREATE_ACCOUNT'
            );
        }

        if ($state === 'awaiting_link_identifier') {
            $linked = $this->linkExistingAccount($phone, $message);
            return $this->response(
                $this->buildLinkOtpReply($linked),
                'LINK_ACCOUNT'
            );
        }

        if ($state === 'awaiting_otp' && (($session->context['purpose'] ?? null) === 'link_account')) {
            $linked = $this->verifyLinkOtp($phone, trim($message));
            return $this->response(
                "Compte lié avec succès. Bienvenue {$linked['display_name']}.",
                'LINK_ACCOUNT'
            );
        }

        $parsed = $this->intentParser->parse($message);

        return match ($parsed['intent']) {
            'CREATE_ACCOUNT' => $this->startCreateAccountFlow($session),
            'LINK_ACCOUNT' => $this->startLinkAccountFlow($session),
            'SUPPORT' => $this->supportResponse($phone, $message),
            default => $this->welcomeResponse(false),
        };
    }

    private function handleKnownUserFlow($session, User $user, string $message): array
    {
        $parsed = $this->intentParser->parse($message);
        $context = $session->context ?? [];

        if ($session->state === 'awaiting_otp' && (($session->context['purpose'] ?? null) === 'link_account')) {
            $linked = $this->verifyLinkOtp($session->user_phone, trim($message));

            return $this->response(
                "Compte lié avec succès. Bienvenue {$linked['display_name']}.",
                'LINK_ACCOUNT'
            );
        }

        if ($session->state === 'awaiting_amount') {
            $amount = $parsed['entities']['amount'] ?? null;
            if (!$amount) {
                return $this->response('Indiquez un montant valide.', 'SEND_MONEY');
            }

            $context['amount'] = $amount;
            $this->conversationState->updateState($session, 'awaiting_receiver', $context, $message);
            return $this->response('Quel est le numéro du destinataire ?', 'SEND_MONEY');
        }

        if ($session->state === 'awaiting_receiver') {
            $receiverPhone = $parsed['entities']['phone'] ?? null;
            if (!$receiverPhone && !empty($parsed['entities']['recipient_name'])) {
                $receiver = $this->findUserByDisplayName((string) $parsed['entities']['recipient_name'], $user->id);
                $receiverPhone = $receiver?->phone;
            }
            if (!$receiverPhone) {
                return $this->response('Envoyez un numéro destinataire valide.', 'SEND_MONEY');
            }

            $context['receiver_phone'] = $receiverPhone;
            $this->conversationState->updateState($session, 'awaiting_transfer_pin', $context, $message);
            return $this->response('Saisissez votre PIN pour confirmer le transfert.', 'SEND_MONEY');
        }

        if ($session->state === 'awaiting_transfer_pin') {
            $amount = (int) ($context['amount'] ?? 0);
            $receiverPhone = (string) ($context['receiver_phone'] ?? '');
            $result = $this->sendMoney($user->whatsapp_phone ?? $user->phone, $receiverPhone, $amount, trim($message));

            if (($result['requires_otp'] ?? false) === true) {
                $this->conversationState->updateState($session, 'awaiting_transfer_otp', $context, $message);
                return $this->response('OTP requis. Répondez avec le code reçu par SMS.', 'SEND_MONEY');
            }

            $this->conversationState->updateState($session, 'idle', [], $message);
            return $this->response(
                "Transfert réussi de {$result['amount']} {$result['currency']} vers {$result['receiver_phone']}.",
                'SEND_MONEY'
            );
        }

        if ($session->state === 'awaiting_transfer_otp') {
            $amount = (int) ($context['amount'] ?? 0);
            $receiverPhone = (string) ($context['receiver_phone'] ?? '');
            $result = $this->verifyTransferOtp(
                $user->whatsapp_phone ?? $user->phone,
                $receiverPhone,
                $amount,
                trim($message),
            );
            $this->conversationState->updateState($session, 'idle', [], $message);

            return $this->response(
                "Transfert réussi de {$result['amount']} {$result['currency']} vers {$result['receiver_phone']}.",
                'SEND_MONEY'
            );
        }

        if ($session->state === 'awaiting_balance_pin') {
            $result = $this->getWalletBalance($user->whatsapp_phone ?? $user->phone, trim($message));
            $this->conversationState->updateState($session, 'idle', [], $message);

            return $this->response(
                "Votre solde disponible est de {$result['balance']} {$result['currency']}." . $this->buildMemorySuffix($user),
                'CHECK_BALANCE'
            );
        }

        if ($session->state === 'awaiting_history_pin') {
            $result = $this->getTransactionHistory($user->whatsapp_phone ?? $user->phone, trim($message));
            $this->conversationState->updateState($session, 'idle', [], $message);

            $lines = collect($result['transactions'])
                ->take(3)
                ->map(fn (array $transaction): string => sprintf(
                    '%s • %s GNF • %s',
                    $transaction['type'],
                    number_format(abs((int) $transaction['amount']), 0, ',', ' '),
                    $transaction['description'] ?? 'Opération'
                ))
                ->implode("\n");

            return $this->response(
                "Voici vos dernières opérations :\n{$lines}",
                'TRANSACTION_HISTORY'
            );
        }

        return match ($parsed['intent']) {
            'GREETING' => $this->welcomeResponse(true, $user),
            'HELP' => $this->helpResponse($user),
            'THANKS' => $this->response('Avec plaisir. Je peux continuer avec votre solde, un transfert, votre historique ou le support.', 'THANKS'),
            'SERVICE_INFO' => $this->response('Sur WhatsApp, NIMBA peut vous guider pour consulter votre solde, lancer un transfert, afficher vos opérations et vous orienter vers le support. Les opérations sensibles restent protégées par PIN et OTP si nécessaire.', 'SERVICE_INFO'),
            'FEES_INFO' => $this->response('Les frais dépendent du type d\'opération. NIMBA vous guide jusqu\'au bon parcours et les validations utiles restent affichées avant confirmation.', 'FEES_INFO'),
            'SECURITY_HELP' => $this->response('Gardez votre PIN secret, ne partagez jamais vos OTP et contactez le support si une opération vous semble inhabituelle.', 'SECURITY_HELP'),
            'LINK_ACCOUNT' => $this->startKnownUserLinkAccountFlow($session, $user),
            'CHECK_BALANCE' => $this->requestBalancePin($session),
            'SEND_MONEY' => $this->startTransferFlow($session, $parsed['entities']),
            'TRANSACTION_HISTORY' => $this->requestHistoryPin($session),
            'SUPPORT' => $this->supportResponse($user->whatsapp_phone ?? $user->phone, $message),
            default => $this->unknownKnownUserResponse($user, $message),
        };
    }

    private function startCreateAccountFlow($session): array
    {
        $this->conversationState->updateState($session, 'awaiting_account_name', []);
        return $this->response('Création de compte. Quel est votre nom complet ?', 'CREATE_ACCOUNT');
    }

    private function startLinkAccountFlow($session): array
    {
        $this->conversationState->updateState($session, 'awaiting_link_identifier', []);
        return $this->response('Quel est le numéro de téléphone du compte existant à associer ?', 'LINK_ACCOUNT');
    }

    private function startKnownUserLinkAccountFlow($session, User $user): array
    {
        if (!empty($user->whatsapp_phone)) {
            return $this->response('Votre compte WhatsApp est déjà associé.', 'LINK_ACCOUNT');
        }

        $linked = $this->linkExistingAccount($session->user_phone, $user->phone);

        return $this->response(
            $this->buildLinkOtpReply($linked),
            'LINK_ACCOUNT'
        );
    }

    private function buildLinkOtpReply(array $linked): string
    {
        $reply = "Un OTP a été envoyé au numéro {$linked['account_phone']}. Répondez avec ce code pour finaliser la liaison.";

        if (!empty($linked['otp'])) {
            $reply .= "\n\nCode OTP (mode développement) : {$linked['otp']}";
        }

        return $reply;
    }

    private function shouldExposeOtpForDevelopment(): bool
    {
        return app()->environment('local') || (bool) config('app.debug', false);
    }

    private function startTransferFlow($session, array $entities): array
    {
        $amount = $entities['amount'] ?? null;
        $phone = $entities['phone'] ?? null;

        if (!$phone && !empty($entities['recipient_name'])) {
            $phone = $this->findUserByDisplayName((string) $entities['recipient_name'])?->phone;
        }

        if ($amount && $phone) {
            $this->conversationState->updateState($session, 'awaiting_transfer_pin', [
                'amount' => $amount,
                'receiver_phone' => $phone,
            ]);

            return $this->response('Saisissez votre PIN pour confirmer le transfert.', 'SEND_MONEY');
        }

        if ($amount) {
            $this->conversationState->updateState($session, 'awaiting_receiver', ['amount' => $amount]);
            return $this->response('Quel est le numéro du destinataire ?', 'SEND_MONEY');
        }

        $this->conversationState->updateState($session, 'awaiting_amount', []);
        return $this->response('Quel montant voulez-vous envoyer ?', 'SEND_MONEY');
    }

    private function welcomeResponse(bool $knownUser, ?User $user = null): array
    {
        $intro = $knownUser
            ? "Bienvenue 👋\nJe suis NIMBA sur WhatsApp."
            : "Bienvenue 👋\nJe suis NIMBA, l'assistant financier WhatsApp.";

        $memorySuffix = $knownUser && $user !== null ? $this->buildMemorySuffix($user) : '';
        $shortcutSuffix = $knownUser && $user !== null ? $this->buildFrequentBeneficiaryText($user) : '';

        return $this->response(
            $intro . $memorySuffix . $shortcutSuffix . "\n\nChoisissez une option :\n1. Créer un compte\n2. Associer un compte existant\n3. Vérifier solde\n4. Envoyer argent\n5. Historique transactions\n6. Support client",
            'MENU'
        );
    }

    private function helpResponse(User $user): array
    {
        return $this->response(
            'Je peux vous aider à vérifier votre solde, envoyer de l\'argent, consulter vos dernières opérations, expliquer le service, parler sécurité ou transmettre votre demande au support.' . $this->buildMemorySuffix($user),
            'HELP'
        );
    }

    private function requestBalancePin($session): array
    {
        $this->conversationState->updateState($session, 'awaiting_balance_pin', []);
        return $this->response('Saisissez votre PIN pour consulter votre solde.', 'CHECK_BALANCE');
    }

    private function requestHistoryPin($session): array
    {
        $this->conversationState->updateState($session, 'awaiting_history_pin', []);
        return $this->response('Saisissez votre PIN pour afficher votre historique.', 'TRANSACTION_HISTORY');
    }

    private function unknownKnownUserResponse(User $user, string $message): array
    {
        $aiResponse = $this->buildAiFallbackResponse($user, $message);
        if ($aiResponse !== null) {
            return $aiResponse;
        }

        $this->assistantMemoryService->rememberUnknownMessage($user, $message, 'whatsapp');

        return $this->response(
            'Je n\'ai pas bien compris. Essayez par exemple : solde, envoyer 20000 à 622000000, historique, quels sont les frais ou comment fonctionne le service ?' . $this->buildMemorySuffix($user),
            'UNKNOWN'
        );
    }

    private function buildAiFallbackResponse(User $user, string $message): ?array
    {
        if (!(bool) config('services.nimba_ai.enable_whatsapp_fallback', true)) {
            return null;
        }

        $result = $this->aiAssistantService->answer(
            $user,
            $message,
            $this->loadRecentTranscript($user),
            [
                'channel' => 'whatsapp',
                'mode' => 'whatsapp',
                'memory_summary' => $this->assistantMemoryService->memorySummaries($user, 3),
            ],
        );

        if ($result === null) {
            return null;
        }

        return $this->response(
            (string) ($result['reply'] ?? ''),
            'AI_FALLBACK',
            [
                'ai_provider' => $result['provider'] ?? config('services.nimba_ai.provider', 'chatgpt'),
                'ai_model' => $result['model'] ?? null,
                'web_references' => $result['web_references'] ?? [],
                'web_search_provider' => $result['web_search_provider'] ?? null,
            ],
        );
    }

    private function supportResponse(string $phone, string $message): array
    {
        $support = $this->createSupportTicket($phone, $message);

        return $this->response(
            "Votre demande a été transmise au support. Référence: {$support['support_request_id']}",
            'SUPPORT'
        );
    }

    private function response(string $reply, string $intent, array $metadata = []): array
    {
        return [
            'success' => true,
            'intent' => $intent,
            'reply' => $reply,
            'metadata' => $metadata,
        ];
    }

    private function resolveUserWithPin(string $phone, string $pin): User
    {
        $user = $this->findUserByPhone($phone);
        if (!$user) {
            throw new RuntimeException('Utilisateur introuvable.');
        }

        if (!$user->pin_hash || !Hash::check($pin, $user->pin_hash)) {
            throw new RuntimeException('PIN invalide.');
        }

        return $user;
    }

    private function findUserByPhone(string $phone): ?User
    {
        $normalized = $this->normalizePhone($phone);

        return User::query()
            ->where('phone', $normalized)
            ->orWhere('whatsapp_phone', $normalized)
            ->first();
    }

    private function getOrCreateWallet(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => config('whatsapp.default_currency', 'GNF'),
                'cash_available' => 0,
                'commission_available' => 0,
                'commission_balance' => 0,
                'blocked_amount' => 0,
            ]
        );
    }

    private function storeMessageLog(
        string $phone,
        ?User $user,
        string $direction,
        string $message,
        ?string $sessionId,
        ?string $intent,
        array $payload,
        ?string $providerMessageId,
        ?Carbon $sentAt = null,
        ?Carbon $receivedAt = null,
    ): void {
        WhatsAppMessageLog::query()->create([
            'user_id' => $user?->id,
            'user_phone' => $phone,
            'session_id' => $sessionId,
            'direction' => $direction,
            'message' => $message,
            'provider_message_id' => $providerMessageId,
            'intent' => $intent,
            'payload' => $payload,
            'status' => 'processed',
            'sent_at' => $sentAt,
            'received_at' => $receivedAt,
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        return $this->gateway->normalizePhone($phone);
    }

    private function executeTransfer(User $user, User $receiver, int $amount): void
    {
        $senderWallet = $this->getOrCreateWallet($user);
        $receiverWallet = $this->getOrCreateWallet($receiver);

        $this->walletService->transfer(
            $senderWallet->id,
            $user->id,
            $receiverWallet->id,
            $receiver->id,
            $amount,
            "Transfert WhatsApp NIMBA vers {$receiver->phone}"
        );

        $this->assistantMemoryService->rememberTransferRecipient($user, $receiver, 'whatsapp', $amount);
    }

    private function findUserByDisplayName(string $name, ?string $excludeUserId = null): ?User
    {
        $query = User::query()->where('display_name', 'like', '%' . trim($name) . '%');

        if ($excludeUserId !== null) {
            $query->where('id', '!=', $excludeUserId);
        }

        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function buildMemorySuffix(User $user): string
    {
        $topics = $this->assistantMemoryService->preferredTopics($user, 2);
        if (empty($topics)) {
            return '';
        }

        return "\n\nJe retiens aussi vos sujets récents : " . implode(', ', $topics) . '.';
    }

    private function buildFrequentBeneficiaryText(User $user): string
    {
        $beneficiaries = $this->assistantMemoryService->frequentBeneficiaries($user, 2);
        if (empty($beneficiaries)) {
            return '';
        }

        $lines = collect($beneficiaries)
            ->map(fn (array $beneficiary): string => '- Envoyer à ' . $beneficiary['display_name'] . ' (' . $beneficiary['phone'] . ')')
            ->implode("\n");

        return "\n\nRaccourcis intelligents :\n{$lines}";
    }

    private function loadRecentTranscript(User $user, int $limit = 6): array
    {
        $phone = $user->whatsapp_phone ?? $user->phone;

        return WhatsAppMessageLog::query()
            ->where(function ($query) use ($user, $phone): void {
                $query->where('user_id', $user->id);

                if ($phone) {
                    $query->orWhere('user_phone', $phone);
                }
            })
            ->latest('created_at')
            ->limit(max(1, $limit))
            ->get(['direction', 'message'])
            ->reverse()
            ->map(fn (WhatsAppMessageLog $log): array => [
                'role' => $log->direction === 'inbound' ? 'user' : 'assistant',
                'content' => (string) $log->message,
            ])
            ->values()
            ->all();
    }

    private function linkOtpCacheKey(string $whatsappPhone): string
    {
        return 'whatsapp_link_otp:' . $whatsappPhone;
    }

    private function transferOtpCacheKey(string $userId): string
    {
        return 'whatsapp_transfer_otp:' . $userId;
    }
}
