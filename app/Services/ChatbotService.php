<?php

namespace App\Services;

use App\Models\ChatHistory;
use App\Models\SupportRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    private const CACHE_TTL_MINUTES = 30;
    private const UNKNOWN_ATTEMPT_THRESHOLD = 2;
    private const DEFAULT_TRANSACTION_LIMIT = 5000000;

    public function __construct(
        private WalletService $walletService,
        private NimbaSmsService $smsService,
    ) {}

    public function handle(User $user, string $message): array
    {
        $message = trim($message);
        $state = $this->getState($user);
        $entities = $this->extractEntities($message);
        $sessionId = $this->getSessionId($user, $state);

        $this->storeHistory(
            user: $user,
            sessionId: $sessionId,
            role: 'user',
            content: $message,
            intent: null,
            entities: $entities,
            context: $state,
        );

        $response = $this->handleStatefulFlow($user, $message, $entities, $state);

        if ($response === null) {
            $intent = $this->detectIntent($message);
            $response = $this->handleIntent($user, $message, $intent, $entities, $state);
        }

        $this->storeHistory(
            user: $user,
            sessionId: $sessionId,
            role: 'assistant',
            content: $response['reply'],
            intent: $response['intent'] ?? null,
            entities: $response['metadata']['entities'] ?? null,
            context: $this->getState($user),
            metadata: $response['metadata'] ?? [],
            escalated: (bool) ($response['support_transferred'] ?? false),
        );

        Log::info('chatbot.message_handled', [
            'user_id' => $user->id,
            'intent' => $response['intent'] ?? 'UNKNOWN',
            'awaiting' => $response['awaiting'] ?? null,
        ]);

        return $response;
    }

    private function handleStatefulFlow(
        User $user,
        string $message,
        array $entities,
        array $state,
    ): ?array {
        $awaiting = $state['awaiting'] ?? null;

        if ($awaiting === null) {
            return null;
        }

        if ($this->isCancelMessage($message)) {
            $this->clearState($user);
            return $this->buildResponse(
                reply: 'Action annulée. Je peux vous aider pour votre solde, un transfert, votre historique, un dépôt, un retrait ou le support.',
                intent: 'CANCELLED',
                buttons: $this->defaultButtons($user),
            );
        }

        return match ($awaiting) {
            'send_amount' => $this->continueSendAmount($user, $entities),
            'send_recipient' => $this->continueSendRecipient($user, $entities),
            'send_confirm' => $this->continueSendConfirmation($user, $message),
            'send_otp' => $this->continueSendOtp($user, $message),
            'withdraw_amount' => $this->continueWithdrawAmount($user, $entities),
            'withdraw_confirm' => $this->continueWithdrawConfirmation($user, $message),
            'withdraw_otp' => $this->continueWithdrawOtp($user, $message),
            'deposit_amount' => $this->continueDepositAmount($user, $entities),
            default => null,
        };
    }

    private function handleIntent(
        User $user,
        string $message,
        string $intent,
        array $entities,
        array $state,
    ): array {
        return match ($intent) {
            'CHECK_BALANCE' => $this->handleBalance($user),
            'SEND_MONEY' => $this->handleSendMoney($user, $entities),
            'TRANSACTION_HISTORY' => $this->handleTransactionHistory($user),
            'PREPAID_BILL' => $this->handlePrepaidBill($user),
            'POSTPAID_BILL' => $this->handlePostpaidBill($user),
            'DEPOSIT' => $this->handleDeposit($user, $entities),
            'WITHDRAW' => $this->handleWithdraw($user, $entities),
            'ACCOUNT_INFO' => $this->handleAccountInfo($user),
            'SUPPORT_HELP' => $this->handleSupport($user, $message, 'user_requested_support'),
            'SECURITY_HELP' => $this->handleSecurityHelp($user),
            default => $this->handleUnknown($user, $message, $state),
        };
    }

    private function handleBalance(User $user): array
    {
        $wallet = $this->getOrCreateWallet($user);
        $available = (int) ($wallet->cash_available - $wallet->blocked_amount);

        $this->clearState($user);

        return $this->buildResponse(
            reply: "Votre solde disponible est de {$this->formatAmount($available)} GNF.",
            intent: 'CHECK_BALANCE',
            buttons: $this->mergeButtons(
                [
                    $this->button('Historique des transactions'),
                    $this->button('Envoyer de l\'argent'),
                ],
                $this->billButtons($user),
            ),
            metadata: [
                'action' => 'open_wallet_balance',
                'available_balance' => $available,
                'blocked_amount' => (int) $wallet->blocked_amount,
                'currency' => $wallet->currency ?? 'GNF',
            ],
        );
    }

    private function handleSendMoney(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;
        $recipient = $this->resolveRecipient($user, $entities);

        if ($amount === null) {
            $this->putState($user, [
                'awaiting' => 'send_amount',
                'intent' => 'SEND_MONEY',
                'session_id' => $this->getSessionId($user),
            ]);

            return $this->buildResponse(
                reply: 'Quel montant voulez-vous envoyer ?',
                intent: 'SEND_MONEY',
                buttons: [$this->button('Annuler')],
                awaiting: 'send_amount',
            );
        }

        $limitCheck = $this->guardTransactionLimit($amount);
        if ($limitCheck !== null) {
            return $limitCheck;
        }

        if ($recipient === null) {
            $this->putState($user, [
                'awaiting' => 'send_recipient',
                'intent' => 'SEND_MONEY',
                'session_id' => $this->getSessionId($user),
                'amount' => $amount,
            ]);

            return $this->buildResponse(
                reply: 'À quel numéro voulez-vous envoyer l\'argent ?',
                intent: 'SEND_MONEY',
                buttons: [$this->button('Annuler')],
                awaiting: 'send_recipient',
                metadata: ['amount' => $amount],
            );
        }

        return $this->prepareSendConfirmation($user, $amount, $recipient);
    }

    private function continueSendAmount(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;
        if ($amount === null) {
            return $this->buildResponse(
                reply: 'Je n\'ai pas reconnu le montant. Indiquez un montant comme 20000.',
                intent: 'SEND_MONEY',
                buttons: [$this->button('Annuler')],
                awaiting: 'send_amount',
            );
        }

        $limitCheck = $this->guardTransactionLimit($amount);
        if ($limitCheck !== null) {
            return $limitCheck;
        }

        $state = $this->getState($user);
        $state['amount'] = $amount;
        $state['awaiting'] = 'send_recipient';
        $this->putState($user, $state);

        return $this->buildResponse(
            reply: 'À quel numéro voulez-vous envoyer l\'argent ?',
            intent: 'SEND_MONEY',
            buttons: [$this->button('Annuler')],
            awaiting: 'send_recipient',
            metadata: ['amount' => $amount],
        );
    }

    private function continueSendRecipient(User $user, array $entities): array
    {
        $recipient = $this->resolveRecipient($user, $entities);
        if ($recipient === null) {
            return $this->buildResponse(
                reply: 'Je n\'ai pas trouvé ce destinataire. Envoyez un numéro valide comme 622000000.',
                intent: 'SEND_MONEY',
                buttons: [$this->button('Annuler')],
                awaiting: 'send_recipient',
            );
        }

        $state = $this->getState($user);
        $amount = (int) ($state['amount'] ?? 0);

        return $this->prepareSendConfirmation($user, $amount, $recipient);
    }

    private function prepareSendConfirmation(User $user, int $amount, User $recipient): array
    {
        $fraudReason = $this->detectSuspiciousTransfer($user, $amount, $recipient);
        if ($fraudReason !== null) {
            return $this->handleSupport(
                $user,
                "Transfert bloqué pour contrôle: {$fraudReason}",
                'fraud_review_send_money',
                [
                    'amount' => $amount,
                    'recipient_id' => $recipient->id,
                    'recipient_phone' => $recipient->phone,
                ]
            );
        }

        $state = [
            'awaiting' => 'send_confirm',
            'intent' => 'SEND_MONEY',
            'session_id' => $this->getSessionId($user),
            'amount' => $amount,
            'recipient_id' => $recipient->id,
            'recipient_phone' => $recipient->phone,
            'recipient_name' => $recipient->display_name,
        ];
        $this->putState($user, $state);

        return $this->buildResponse(
            reply: "Confirmez-vous l'envoi de {$this->formatAmount($amount)} GNF au numéro {$recipient->phone} ?",
            intent: 'SEND_MONEY',
            buttons: [
                $this->button('Oui, confirmer'),
                $this->button('Annuler'),
            ],
            awaiting: 'send_confirm',
            metadata: [
                'amount' => $amount,
                'recipient' => [
                    'id' => $recipient->id,
                    'display_name' => $recipient->display_name,
                    'phone' => $recipient->phone,
                ],
            ],
        );
    }

    private function continueSendConfirmation(User $user, string $message): array
    {
        if (!$this->isAffirmative($message)) {
            if ($this->isNegative($message)) {
                $this->clearState($user);
                return $this->buildResponse(
                    reply: 'Transfert annulé.',
                    intent: 'SEND_MONEY',
                    buttons: $this->defaultButtons($user),
                );
            }

            return $this->buildResponse(
                reply: 'Répondez par oui pour confirmer ou annuler pour arrêter l\'opération.',
                intent: 'SEND_MONEY',
                buttons: [
                    $this->button('Oui, confirmer'),
                    $this->button('Annuler'),
                ],
                awaiting: 'send_confirm',
            );
        }

        $state = $this->getState($user);
        $otpResult = $this->sendOtp($user, 'send_money');
        if (!$otpResult['success']) {
            return $this->handleSupport(
                $user,
                'Échec d\'envoi OTP pour transfert',
                'otp_delivery_failed',
                ['flow' => 'send_money']
            );
        }

        $state['awaiting'] = 'send_otp';
        $state['otp_context'] = 'send_money';
        $state['otp_failures'] = 0;
        $this->putState($user, $state);

        return $this->buildResponse(
            reply: $this->buildOtpPrompt(
                fallbackUsed: (bool) ($otpResult['fallback'] ?? false),
                operationLabel: 'la transaction'
            ),
            intent: 'SEND_MONEY',
            buttons: [$this->button('Annuler')],
            awaiting: 'send_otp',
            requiresOtp: true,
            metadata: [
                'otp_delivery' => (string) ($otpResult['delivery'] ?? 'sms'),
            ],
        );
    }

    private function continueSendOtp(User $user, string $message): array
    {
        $otp = preg_replace('/\D+/', '', $message) ?? '';
        if (strlen($otp) !== 6 || !$user->validateTwoFactorCode($otp)) {
            $state = $this->getState($user);
            $failures = (int) ($state['otp_failures'] ?? 0) + 1;
            $state['otp_failures'] = $failures;
            $this->putState($user, $state);

            if ($failures >= 3) {
                return $this->handleSupport(
                    $user,
                    'Trop d\'échecs OTP sur transfert',
                    'otp_failed_send_money',
                    ['flow' => 'send_money', 'failures' => $failures]
                );
            }

            return $this->buildResponse(
                reply: 'Code OTP invalide ou expiré. Réessayez avec le code reçu par SMS.',
                intent: 'SEND_MONEY',
                buttons: [$this->button('Annuler')],
                awaiting: 'send_otp',
                requiresOtp: true,
            );
        }

        $user->resetTwoFactorCode();
        $state = $this->getState($user);

        try {
            $senderWallet = $this->getOrCreateWallet($user);
            $recipient = User::findOrFail($state['recipient_id']);
            $recipientWallet = $this->getOrCreateWallet($recipient);
            $amount = (int) $state['amount'];

            if ($recipient->id === $user->id) {
                throw new \RuntimeException('Vous ne pouvez pas vous transférer de l\'argent à vous-même.');
            }

            $available = (int) ($senderWallet->cash_available - $senderWallet->blocked_amount);
            if ($available < $amount) {
                throw new \RuntimeException("Solde insuffisant. Disponible: {$available} GNF.");
            }

            $this->enforceRecipientWalletLimit($recipient, $recipientWallet, $amount);

            $success = $this->walletService->transfer(
                fromWalletId: $senderWallet->id,
                fromUserId: $user->id,
                toWalletId: $recipientWallet->id,
                toUserId: $recipient->id,
                amount: $amount,
                description: "Transfert chatbot vers {$recipient->display_name} ({$recipient->phone})",
            );

            if (!$success) {
                throw new \RuntimeException('Le transfert n\'a pas pu être exécuté.');
            }

            $senderWallet->refresh();
            $this->clearState($user);

            return $this->buildResponse(
                reply: "Transfert effectué avec succès. {$this->formatAmount($amount)} GNF ont été envoyés à {$recipient->display_name}.",
                intent: 'SEND_MONEY',
                buttons: [
                    $this->button('Vérifier mon solde'),
                    $this->button('Historique des transactions'),
                ],
                metadata: [
                    'amount' => $amount,
                    'recipient' => [
                        'id' => $recipient->id,
                        'display_name' => $recipient->display_name,
                        'phone' => $recipient->phone,
                    ],
                    'new_balance' => (int) $senderWallet->cash_available,
                ],
            );
        } catch (\Throwable $error) {
            Log::warning('chatbot.transfer_failed', [
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);

            return $this->handleSupport(
                $user,
                'Erreur lors du transfert chatbot: ' . $error->getMessage(),
                'transfer_execution_failed',
                ['flow' => 'send_money']
            );
        }
    }

    private function handleTransactionHistory(User $user): array
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(5)
            ->get(['amount', 'type', 'description', 'created_at']);

        $this->clearState($user);

        if ($transactions->isEmpty()) {
            return $this->buildResponse(
                reply: 'Je n\'ai trouvé aucune transaction récente sur votre compte.',
                intent: 'TRANSACTION_HISTORY',
                buttons: $this->defaultButtons($user),
            );
        }

        $lines = $transactions
            ->map(function (WalletTransaction $transaction): string {
                $sign = $transaction->amount >= 0 ? '+' : '-';
                $amount = $this->formatAmount(abs((int) $transaction->amount));
                $date = Carbon::parse($transaction->created_at)->format('d/m H:i');
                return "{$date} • {$transaction->type} • {$sign}{$amount} GNF";
            })
            ->implode("\n");

        return $this->buildResponse(
            reply: "Voici vos 5 dernières transactions :\n{$lines}",
            intent: 'TRANSACTION_HISTORY',
            buttons: $this->mergeButtons(
                [
                    $this->button('Vérifier mon solde'),
                    $this->button('Support client'),
                ],
                $this->billButtons($user),
            ),
            metadata: [
                'action' => 'open_transaction_history',
                'transactions_count' => $transactions->count(),
            ],
        );
    }

    private function handleDeposit(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;

        if ($amount === null) {
            $this->putState($user, [
                'awaiting' => 'deposit_amount',
                'intent' => 'DEPOSIT',
                'session_id' => $this->getSessionId($user),
            ]);

            return $this->buildResponse(
                reply: 'Quel montant souhaitez-vous déposer ?',
                intent: 'DEPOSIT',
                buttons: [$this->button('Annuler')],
                awaiting: 'deposit_amount',
            );
        }

        return $this->continueDepositAmount($user, ['amount' => $amount]);
    }

    private function handlePrepaidBill(User $user): array
    {
        $this->clearState($user);

        if (!$this->supportsBillPayments($user)) {
            return $this->buildUnsupportedBillPaymentResponse($user, 'prépayées');
        }

        $reply = $this->roleSlug($user) === 'pro'
            ? 'J\'ouvre le parcours PRO de paiement prépayé pour acheter de l\'énergie EDG.'
            : 'J\'ouvre le parcours client de paiement prépayé pour acheter de l\'énergie EDG.';

        return $this->buildResponse(
            reply: $reply,
            intent: 'PREPAID_BILL',
            buttons: $this->mergeButtons($this->billButtons($user), [$this->button('Support client')]),
            metadata: [
                'action' => 'open_prepaid_bill_flow',
                'role' => $this->roleSlug($user),
            ],
        );
    }

    private function handlePostpaidBill(User $user): array
    {
        $this->clearState($user);

        if (!$this->supportsBillPayments($user)) {
            return $this->buildUnsupportedBillPaymentResponse($user, 'postpayées');
        }

        $reply = $this->roleSlug($user) === 'pro'
            ? 'J\'ouvre le parcours PRO de paiement postpayé pour régler une facture EDG.'
            : 'J\'ouvre le parcours client de paiement postpayé pour régler une facture EDG.';

        return $this->buildResponse(
            reply: $reply,
            intent: 'POSTPAID_BILL',
            buttons: $this->mergeButtons($this->billButtons($user), [$this->button('Support client')]),
            metadata: [
                'action' => 'open_postpaid_bill_flow',
                'role' => $this->roleSlug($user),
            ],
        );
    }

    private function continueDepositAmount(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;
        if ($amount === null) {
            return $this->buildResponse(
                reply: 'Je n\'ai pas reconnu le montant du dépôt. Donnez un montant comme 50000.',
                intent: 'DEPOSIT',
                buttons: [$this->button('Annuler')],
                awaiting: 'deposit_amount',
            );
        }

        $this->clearState($user);

        return $this->buildResponse(
            reply: "J'ai préparé une demande de dépôt de {$this->formatAmount($amount)} GNF. Pour des raisons de sécurité, le dépôt se finalise dans le parcours sécurisé de recharge de l'application.",
            intent: 'DEPOSIT',
            buttons: $this->mergeButtons(
                [
                    $this->button('Dépôt'),
                    $this->button('Support client'),
                ],
                $this->billButtons($user),
            ),
            metadata: [
                'amount' => $amount,
                'action' => 'open_secure_deposit_flow',
            ],
        );
    }

    private function handleWithdraw(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;

        if ($amount === null) {
            $this->putState($user, [
                'awaiting' => 'withdraw_amount',
                'intent' => 'WITHDRAW',
                'session_id' => $this->getSessionId($user),
            ]);

            return $this->buildResponse(
                reply: 'Quel montant souhaitez-vous retirer ?',
                intent: 'WITHDRAW',
                buttons: [$this->button('Annuler')],
                awaiting: 'withdraw_amount',
            );
        }

        return $this->prepareWithdrawalConfirmation($user, $amount);
    }

    private function continueWithdrawAmount(User $user, array $entities): array
    {
        $amount = $entities['amount'] ?? null;
        if ($amount === null) {
            return $this->buildResponse(
                reply: 'Je n\'ai pas reconnu le montant. Indiquez un montant comme 30000.',
                intent: 'WITHDRAW',
                buttons: [$this->button('Annuler')],
                awaiting: 'withdraw_amount',
            );
        }

        return $this->prepareWithdrawalConfirmation($user, $amount);
    }

    private function prepareWithdrawalConfirmation(User $user, int $amount): array
    {
        $limitCheck = $this->guardTransactionLimit($amount);
        if ($limitCheck !== null) {
            return $limitCheck;
        }

        $fraudReason = $this->detectSuspiciousWithdrawal($user, $amount);
        if ($fraudReason !== null) {
            return $this->handleSupport(
                $user,
                "Retrait bloqué pour contrôle: {$fraudReason}",
                'fraud_review_withdraw',
                ['amount' => $amount]
            );
        }

        $wallet = $this->getOrCreateWallet($user);
        $available = (int) ($wallet->cash_available - $wallet->blocked_amount);
        if ($available < $amount) {
            $this->clearState($user);
            return $this->buildResponse(
                reply: "Solde insuffisant pour ce retrait. Disponible: {$this->formatAmount($available)} GNF.",
                intent: 'WITHDRAW',
                buttons: [
                    $this->button('Vérifier mon solde'),
                    $this->button('Support client'),
                ],
            );
        }

        $this->putState($user, [
            'awaiting' => 'withdraw_confirm',
            'intent' => 'WITHDRAW',
            'session_id' => $this->getSessionId($user),
            'amount' => $amount,
        ]);

        return $this->buildResponse(
            reply: "Confirmez-vous la création d'une demande de retrait de {$this->formatAmount($amount)} GNF ?",
            intent: 'WITHDRAW',
            buttons: [
                $this->button('Oui, confirmer'),
                $this->button('Annuler'),
            ],
            awaiting: 'withdraw_confirm',
            metadata: ['amount' => $amount],
        );
    }

    private function continueWithdrawConfirmation(User $user, string $message): array
    {
        if (!$this->isAffirmative($message)) {
            if ($this->isNegative($message)) {
                $this->clearState($user);
                return $this->buildResponse(
                    reply: 'Demande de retrait annulée.',
                    intent: 'WITHDRAW',
                    buttons: $this->defaultButtons($user),
                );
            }

            return $this->buildResponse(
                reply: 'Répondez par oui pour confirmer ou annuler pour arrêter la demande.',
                intent: 'WITHDRAW',
                buttons: [
                    $this->button('Oui, confirmer'),
                    $this->button('Annuler'),
                ],
                awaiting: 'withdraw_confirm',
            );
        }

        $otpResult = $this->sendOtp($user, 'withdraw');
        if (!$otpResult['success']) {
            return $this->handleSupport(
                $user,
                'Échec d\'envoi OTP pour retrait',
                'otp_delivery_failed',
                ['flow' => 'withdraw']
            );
        }

        $state = $this->getState($user);
        $state['awaiting'] = 'withdraw_otp';
        $state['otp_context'] = 'withdraw';
        $state['otp_failures'] = 0;
        $this->putState($user, $state);

        return $this->buildResponse(
            reply: $this->buildOtpPrompt(
                fallbackUsed: (bool) ($otpResult['fallback'] ?? false),
                operationLabel: 'la demande de retrait'
            ),
            intent: 'WITHDRAW',
            buttons: [$this->button('Annuler')],
            awaiting: 'withdraw_otp',
            requiresOtp: true,
            metadata: [
                'otp_delivery' => (string) ($otpResult['delivery'] ?? 'sms'),
            ],
        );
    }

    private function continueWithdrawOtp(User $user, string $message): array
    {
        $otp = preg_replace('/\D+/', '', $message) ?? '';
        if (strlen($otp) !== 6 || !$user->validateTwoFactorCode($otp)) {
            $state = $this->getState($user);
            $failures = (int) ($state['otp_failures'] ?? 0) + 1;
            $state['otp_failures'] = $failures;
            $this->putState($user, $state);

            if ($failures >= 3) {
                return $this->handleSupport(
                    $user,
                    'Trop d\'échecs OTP sur retrait',
                    'otp_failed_withdraw',
                    ['flow' => 'withdraw', 'failures' => $failures]
                );
            }

            return $this->buildResponse(
                reply: 'Code OTP invalide ou expiré. Réessayez avec le code reçu par SMS.',
                intent: 'WITHDRAW',
                buttons: [$this->button('Annuler')],
                awaiting: 'withdraw_otp',
                requiresOtp: true,
            );
        }

        $user->resetTwoFactorCode();
        $state = $this->getState($user);

        try {
            $amount = (int) $state['amount'];
            $result = $this->walletService->withdrawalRequest(
                fromUserId: $user->id,
                toUserId: $user->id,
                amount: $amount,
                description: 'Demande de retrait créée via chatbot',
                metadata: ['source' => 'chatbot'],
            );

            $this->clearState($user);

            return $this->buildResponse(
                reply: "Votre demande de retrait de {$this->formatAmount($amount)} GNF a été enregistrée et envoyée pour traitement.",
                intent: 'WITHDRAW',
                buttons: [
                    $this->button('Historique des transactions'),
                    $this->button('Support client'),
                ],
                metadata: $result,
            );
        } catch (\Throwable $error) {
            Log::warning('chatbot.withdraw_failed', [
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);

            return $this->handleSupport(
                $user,
                'Erreur lors du retrait chatbot: ' . $error->getMessage(),
                'withdraw_execution_failed',
                ['flow' => 'withdraw']
            );
        }
    }

    private function handleAccountInfo(User $user): array
    {
        $this->clearState($user);

        $role = $user->role?->slug ?? 'inconnu';
        $twoFactor = $user->two_factor_enabled ? 'activée' : 'désactivée';

        return $this->buildResponse(
            reply: "Compte: {$user->display_name}. Téléphone: {$user->phone}. Rôle: {$role}. 2FA: {$twoFactor}.",
            intent: 'ACCOUNT_INFO',
            buttons: $this->mergeButtons(
                [
                    $this->button('Sécurité du compte'),
                    $this->button('Support client'),
                ],
                $this->billButtons($user),
            ),
            metadata: [
                'display_name' => $user->display_name,
                'phone' => $user->phone,
                'role' => $role,
                'two_factor_enabled' => (bool) $user->two_factor_enabled,
            ],
        );
    }

    private function handleSecurityHelp(User $user): array
    {
        $this->clearState($user);

        return $this->buildResponse(
            reply: 'Pour sécuriser votre compte, gardez la 2FA activée, ne partagez jamais vos codes OTP, vérifiez toujours le numéro du destinataire et contactez le support en cas d\'activité inhabituelle.',
            intent: 'SECURITY_HELP',
            buttons: $this->mergeButtons(
                [
                    $this->button('Support client'),
                    $this->button('Informations sur mon compte'),
                ],
                $this->billButtons($user),
            ),
            metadata: [
                'two_factor_enabled' => (bool) $user->two_factor_enabled,
            ],
        );
    }

    private function handleSupport(
        User $user,
        string $message,
        string $reason,
        array $metadata = [],
    ): array {
        $supportRequest = SupportRequest::create([
            'user_id' => $user->id,
            'source' => 'chatbot',
            'reason' => $reason,
            'status' => 'open',
            'last_user_message' => $message,
            'transcript' => $this->loadTranscript($user),
            'metadata' => $metadata,
            'transferred_at' => now(),
        ]);

        $this->clearState($user);

        return $this->buildResponse(
            reply: 'Je vais vous mettre en relation avec un agent du support. Votre conversation a été enregistrée et transmise.',
            intent: 'SUPPORT_HELP',
            buttons: [
                $this->button('Historique des transactions'),
                $this->button('Vérifier mon solde'),
            ],
            supportTransferred: true,
            metadata: array_merge($metadata, ['support_request_id' => $supportRequest->id]),
        );
    }

    private function handleUnknown(User $user, string $message, array $state): array
    {
        $attempts = ((int) ($state['unknown_attempts'] ?? 0)) + 1;

        if ($attempts >= self::UNKNOWN_ATTEMPT_THRESHOLD) {
            return $this->handleSupport($user, $message, 'unrecognized_after_retries', ['attempts' => $attempts]);
        }

        $this->putState($user, [
            'session_id' => $this->getSessionId($user),
            'unknown_attempts' => $attempts,
        ]);

        return $this->buildResponse(
            reply: 'Je n\'ai pas bien compris. Vous pouvez demander votre solde, un envoi d\'argent, l\'historique, un dépôt, un retrait ou le support.',
            intent: 'UNKNOWN',
            buttons: $this->defaultButtons($user),
            metadata: ['attempts' => $attempts],
        );
    }

    private function detectIntent(string $message): string
    {
        $normalized = $this->normalize($message);

        if ($this->matchesConfiguredIntent($normalized, 'check_balance')) {
            return 'CHECK_BALANCE';
        }

        if ($this->matchesConfiguredIntent($normalized, 'send_money')) {
            return 'SEND_MONEY';
        }

        if ($this->matchesConfiguredIntent($normalized, 'transaction_history')) {
            return 'TRANSACTION_HISTORY';
        }

        if ($this->matchesConfiguredIntent($normalized, 'prepaid_bill')) {
            return 'PREPAID_BILL';
        }

        if ($this->matchesConfiguredIntent($normalized, 'postpaid_bill')) {
            return 'POSTPAID_BILL';
        }

        if ($this->matchesConfiguredIntent($normalized, 'deposit')) {
            return 'DEPOSIT';
        }

        if ($this->matchesConfiguredIntent($normalized, 'withdraw')) {
            return 'WITHDRAW';
        }

        if ($this->matchesConfiguredIntent($normalized, 'account_info')) {
            return 'ACCOUNT_INFO';
        }

        if ($this->matchesConfiguredIntent($normalized, 'support_help')) {
            return 'SUPPORT_HELP';
        }

        if ($this->matchesConfiguredIntent($normalized, 'security_help')) {
            return 'SECURITY_HELP';
        }

        return 'UNKNOWN';
    }

    private function extractEntities(string $message): array
    {
        $entities = [];
        $clean = preg_replace('/\s+/', ' ', trim($message)) ?? '';
        $compact = preg_replace('/\s+/', '', $clean) ?? '';

        if (preg_match('/(?<!\d)(62|65|66)\d{7}(?!\d)/', $compact, $phoneMatch)) {
            $entities['phone'] = $phoneMatch[0];
        }

        if (preg_match_all('/(?<!\d)(\d{1,3}(?:[\s.,]\d{3})+|\d{4,9})(?!\d)/', $clean, $amountMatches)) {
            foreach ($amountMatches[1] as $candidate) {
                $numeric = (int) preg_replace('/\D+/', '', $candidate);
                if ($numeric < 1000) {
                    continue;
                }

                if (($entities['phone'] ?? null) !== null && $numeric === (int) $entities['phone']) {
                    continue;
                }

                $entities['amount'] = $numeric;
                break;
            }
        }

        if (preg_match('/(?:a|à)\s+([A-Za-zÀ-ÿ\-\']{2,}(?:\s+[A-Za-zÀ-ÿ\-\']{2,})?)/u', $clean, $nameMatch)) {
            $candidate = trim($nameMatch[1]);
            if (!preg_match('/^(62|65|66)\d{7}$/', preg_replace('/\s+/', '', $candidate) ?? '')) {
                $entities['recipient_name'] = $candidate;
            }
        }

        return $entities;
    }

    private function resolveRecipient(User $actor, array $entities): ?User
    {
        $phone = $entities['phone'] ?? null;
        if ($phone !== null) {
            return User::query()
                ->where('phone', $this->normalizePhone($phone))
                ->where('id', '!=', $actor->id)
                ->first();
        }

        $name = $entities['recipient_name'] ?? null;
        if ($name === null) {
            return null;
        }

        $matches = User::query()
            ->where('id', '!=', $actor->id)
            ->where('display_name', 'like', '%' . trim($name) . '%')
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function sendOtp(User $user, string $context): array
    {
        $otp = $user->generateTwoFactorCode();

        $message = match ($context) {
            'withdraw' => "Votre code OTP EDGPAY pour confirmer le retrait est : {$otp}. Il expire dans 10 minutes.",
            default => "Votre code OTP EDGPAY pour confirmer la transaction est : {$otp}. Il expire dans 10 minutes.",
        };

        $result = $this->smsService->sendSingleSms('MDING', $user->phone, $message);

        if (($result['success'] ?? false) === true) {
            return array_merge($result, ['delivery' => 'sms']);
        }

        if ($this->shouldAllowOtpFallback()) {
            Log::warning('chatbot.otp_delivery_fallback', [
                'user_id' => $user->id,
                'context' => $context,
                'error' => $result['error'] ?? 'unknown_sms_failure',
            ]);

            return [
                'success' => true,
                'fallback' => true,
                'delivery' => 'local_fallback',
                'error' => $result['error'] ?? null,
            ];
        }

        return $result;
    }

    private function detectSuspiciousTransfer(User $user, int $amount, User $recipient): ?string
    {
        if (!$recipient->status) {
            return 'destinataire inactif';
        }

        if ($amount >= (int) floor($this->getTransactionLimit() * 0.8)) {
            return 'montant élevé';
        }

        $recentTransfers = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'transfer_out')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentTransfers >= 3) {
            return 'activité inhabituelle détectée';
        }

        return null;
    }

    private function detectSuspiciousWithdrawal(User $user, int $amount): ?string
    {
        if ($amount >= (int) floor($this->getTransactionLimit() * 0.8)) {
            return 'montant élevé';
        }

        $recentRequests = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['withdrawal', 'withdrawal_approved'])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentRequests >= 3) {
            return 'multiples retraits récents';
        }

        return null;
    }

    private function enforceRecipientWalletLimit(User $recipient, $wallet, int $amount): void
    {
        $role = (string) ($recipient->role?->slug ?? '');
        if ($role !== 'client') {
            return;
        }

        $maxBalance = $this->getIntegerSetting('max_client_wallet_balance', 1000000000);
        $projected = (int) $wallet->cash_available + $amount;

        if ($projected > $maxBalance) {
            throw new \RuntimeException(
                "Le solde du destinataire dépasserait la limite autorisée ({$maxBalance} GNF)."
            );
        }
    }

    private function guardTransactionLimit(int $amount): ?array
    {
        $limit = $this->getTransactionLimit();
        if ($amount > $limit) {
            return $this->buildResponse(
                reply: "Le montant demandé dépasse la limite chatbot de {$this->formatAmount($limit)} GNF. Je vous mets en relation avec le support pour finaliser l'opération.",
                intent: 'SECURITY_HELP',
                buttons: [$this->button('Support client')],
                supportTransferred: true,
                metadata: ['transaction_limit' => $limit],
            );
        }

        return null;
    }

    private function getOrCreateWallet(User $user)
    {
        try {
            return $this->walletService->getWalletByUserId($user->id);
        } catch (ModelNotFoundException) {
            $result = $this->walletService->createWalletForUser($user->id);
            return $result['wallet'];
        }
    }

    private function getTransactionLimit(): int
    {
        return $this->getIntegerSetting('chatbot_transaction_limit', self::DEFAULT_TRANSACTION_LIMIT);
    }

    private function getIntegerSetting(string $key, int $default): int
    {
        $setting = SystemSetting::query()->where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        $raw = preg_replace('/[^\d]/', '', (string) $setting->value);
        return (int) ($raw === '' ? $default : $raw);
    }

    private function getState(User $user): array
    {
        return Cache::get($this->stateKey($user), []);
    }

    private function putState(User $user, array $state): void
    {
        Cache::put($this->stateKey($user), $state, now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    private function clearState(User $user): void
    {
        Cache::forget($this->stateKey($user));
    }

    private function stateKey(User $user): string
    {
        return 'chatbot_state_' . $user->id;
    }

    private function getSessionId(User $user, ?array $state = null): string
    {
        $state ??= $this->getState($user);
        return (string) ($state['session_id'] ?? ('chatbot-' . $user->id));
    }

    private function storeHistory(
        User $user,
        string $sessionId,
        string $role,
        string $content,
        ?string $intent,
        ?array $entities,
        ?array $context,
        array $metadata = [],
        bool $escalated = false,
    ): void {
        ChatHistory::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            'intent' => $intent,
            'entities' => $entities,
            'context' => $context,
            'metadata' => $metadata,
            'escalated_to_support' => $escalated,
        ]);
    }

    private function loadTranscript(User $user): array
    {
        return ChatHistory::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(20)
            ->get(['role', 'content', 'intent', 'created_at'])
            ->reverse()
            ->values()
            ->toArray();
    }

    private function buildResponse(
        string $reply,
        string $intent,
        array $buttons = [],
        ?string $awaiting = null,
        bool $requiresOtp = false,
        bool $supportTransferred = false,
        array $metadata = [],
    ): array {
        return [
            'reply' => $reply,
            'intent' => $intent,
            'buttons' => $buttons,
            'awaiting' => $awaiting,
            'requires_otp' => $requiresOtp,
            'support_transferred' => $supportTransferred,
            'metadata' => $metadata,
        ];
    }

    private function defaultButtons(?User $user = null): array
    {
        return $this->mergeButtons(
            [
                $this->button('Envoyer de l\'argent'),
                $this->button('Vérifier mon solde'),
                $this->button('Historique des transactions'),
                $this->button('Dépôt'),
                $this->button('Retrait'),
                $this->button('Support client'),
            ],
            $user ? $this->billButtons($user) : [],
        );
    }

    private function billButtons(User $user): array
    {
        if (!$this->supportsBillPayments($user)) {
            return [];
        }

        return [
            $this->button('Facture prepayee'),
            $this->button('Facture postpayee'),
        ];
    }

    private function supportsBillPayments(User $user): bool
    {
        return in_array($this->roleSlug($user), ['client', 'pro'], true);
    }

    private function roleSlug(User $user): string
    {
        return (string) ($user->role?->slug ?? '');
    }

    private function mergeButtons(array ...$buttonGroups): array
    {
        $merged = [];

        foreach ($buttonGroups as $group) {
            foreach ($group as $button) {
                $label = (string) ($button['label'] ?? '');
                if ($label === '' || isset($merged[$label])) {
                    continue;
                }

                $merged[$label] = $button;
            }
        }

        return array_values($merged);
    }

    private function matchesConfiguredIntent(string $normalizedMessage, string $intentKey): bool
    {
        $keywords = $this->getIntentKeywords($intentKey);
        return $this->matchesAny($normalizedMessage, is_array($keywords) ? $keywords : []);
    }

    private function getIntentKeywords(string $intentKey): array
    {
        $settingKey = "chatbot_intent_keywords_{$intentKey}";
        $setting = SystemSetting::query()
            ->where('key', $settingKey)
            ->where('is_active', true)
            ->first();

        if ($setting && is_string($setting->value) && trim($setting->value) !== '') {
            return array_values(array_filter(array_map(
                fn (string $keyword): string => trim($this->normalize($keyword)),
                preg_split('/[,\n\r]+/', $setting->value) ?: []
            )));
        }

        $keywords = config("chatbot.intent_keywords.{$intentKey}", []);
        return is_array($keywords) ? $keywords : [];
    }

    private function buildUnsupportedBillPaymentResponse(User $user, string $billTypeLabel): array
    {
        return $this->buildResponse(
            reply: "Le paiement de factures {$billTypeLabel} via le chatbot est réservé aux comptes client et PRO. Je peux vous aider autrement ou vous orienter vers le support.",
            intent: 'SUPPORT_HELP',
            buttons: $this->defaultButtons($user),
            metadata: [
                'bill_payment_supported' => false,
                'role' => $this->roleSlug($user),
            ],
        );
    }

    private function button(string $label): array
    {
        return ['label' => $label, 'value' => $label];
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['é', 'è', 'ê', 'ë', 'à', 'â', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ç'], ['e', 'e', 'e', 'e', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'c'], $value);
        return trim($value);
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function shouldAllowOtpFallback(): bool
    {
        return (bool) config('chatbot.allow_otp_fallback', false);
    }

    private function buildOtpPrompt(bool $fallbackUsed, string $operationLabel): string
    {
        if ($fallbackUsed) {
            return "Le code OTP a ete genere pour confirmer {$operationLabel}. Le SMS n'est pas disponible dans cet environnement de test. Saisissez le code OTP genere pour continuer.";
        }

        return "Veuillez entrer le code OTP envoye par SMS pour confirmer {$operationLabel}.";
    }

    private function isAffirmative(string $message): bool
    {
        return $this->matchesAny($this->normalize($message), ['oui', 'confirmer', 'ok', 'daccord', 'j confirme']);
    }

    private function isNegative(string $message): bool
    {
        return $this->matchesAny($this->normalize($message), ['non', 'annuler', 'stop']);
    }

    private function isCancelMessage(string $message): bool
    {
        return $this->matchesAny($this->normalize($message), ['annuler', 'stop', 'laisser']);
    }
}