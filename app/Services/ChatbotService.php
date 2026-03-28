<?php

namespace App\Services;

use App\Models\ChatHistory;
use App\Models\SupportRequest;
use App\Models\SystemSetting;
use App\Models\TrocPhonePrice;
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
    private array $currentAgentProfile = [];

    public function __construct(
        private WalletService $walletService,
        private NimbaSmsService $smsService,
        private AssistantMemoryService $assistantMemoryService,
        private NimbaAiAssistantService $aiAssistantService,
    ) {}

    public function handle(User $user, string $message, ?string $selectedAgent = null): array
    {
        $message = trim($message);
        $this->currentAgentProfile = $this->resolveConversationalAgent($selectedAgent, $user);
        $state = $this->getState($user);
        $entities = $this->extractEntities($message);
        $sessionId = $this->getSessionId($user, $state);
        $analysis = $this->analyzeIncomingMessage($message, $entities, $state);

        $this->storeHistory(
            user: $user,
            sessionId: $sessionId,
            role: 'user',
            content: $message,
            intent: $analysis['intent_guess'] ?? null,
            entities: $entities,
            context: $state,
            metadata: Arr::except($analysis, ['intent_guess']),
        );

        $response = $this->handleStatefulFlow($user, $message, $entities, $state);

        if ($response === null) {
            $intent = $this->detectIntent($message);
            $knowledgeResponse = $this->resolveKnowledgeResponse($user, $message);
            if ($knowledgeResponse !== null && $this->shouldPreferKnowledgeResponse($message, $intent)) {
                $response = $knowledgeResponse;
            } else {
                $response = $this->handleIntent($user, $message, $intent, $entities, $state);
            }
        }

        $response['metadata'] = $this->decorateResponseMetadata($user, $response, $analysis);

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

        $this->updateLongTermMemory($user, $response);

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
            'GREETING' => $this->handleGreeting($user),
            'HELP' => $this->handleHelp($user),
            'THANKS' => $this->handleThanks($user),
            'SERVICE_INFO' => $this->handleServiceInfo($user),
            'FEES_INFO' => $this->handleFeesInfo($user),
            'CHECK_BALANCE' => $this->handleBalance($user),
            'SEND_MONEY' => $this->handleSendMoney($user, $entities),
            'TRANSACTION_HISTORY' => $this->handleTransactionHistory($user),
            'PREPAID_BILL' => $this->handlePrepaidBill($user),
            'POSTPAID_BILL' => $this->handlePostpaidBill($user),
            'DEPOSIT' => $this->handleDeposit($user, $entities),
            'WITHDRAW' => $this->handleWithdraw($user, $entities),
            'TROC_PHONE' => $this->handleTrocPhone($user),
            'ACCOUNT_INFO' => $this->handleAccountInfo($user),
            'SUPPORT_HELP' => $this->handleSupport($user, $message, 'user_requested_support'),
            'SECURITY_HELP' => $this->handleSecurityHelp($user),
            default => $this->resolveKnowledgeResponse($user, $message)
                ?? $this->handleUnknown($user, $message, $state),
        };
    }

    private function handleGreeting(User $user): array
    {
        $this->clearState($user);

        return $this->buildResponse(
            reply: sprintf(
                '%s %s. Agent actif: %s. Je peux vous aider rapidement avec votre solde, un transfert, votre historique, un dépôt, un retrait ou vos factures EDG.',
                $this->timeBasedGreeting(),
                $user->display_name,
                $this->currentAgentLabel(),
            ),
            intent: 'GREETING',
            buttons: $this->defaultButtons($user),
            metadata: $this->buildPersonalizedMetadata($user, [
                'summary' => $this->buildCapabilitySummary($user),
                'recent_activity' => $this->describeRecentActivity($user),
            ]),
        );
    }

    private function handleHelp(User $user): array
    {
        $this->clearState($user);

        return $this->buildResponse(
            reply: "Voici ce que je peux faire pour vous :\n- consulter votre solde\n- envoyer de l'argent\n- montrer l'historique récent\n- lancer un dépôt ou un retrait\n- ouvrir le paiement EDG\n- vous orienter vers le support si nécessaire",
            intent: 'HELP',
            buttons: $this->defaultButtons($user),
            metadata: $this->buildPersonalizedMetadata($user, [
                'agent_note' => 'Agent actif : ' . $this->currentAgentLabel(),
                'summary' => $this->buildCapabilitySummary($user),
                'recent_activity' => $this->describeRecentActivity($user),
                'tips' => [
                    'Essayez par exemple : Quel est mon solde ?',
                    'Ou encore : Envoyer 25000 à 622000000',
                    'Vous pouvez aussi demander : Quels sont les frais ?',
                ],
            ]),
        );
    }

    private function handleServiceInfo(User $user): array
    {
        $this->clearState($user);

        return $this->buildResponse(
            reply: 'EdgPay vous permet de consulter votre solde, envoyer de l\'argent, suivre vos transactions, gérer vos dépôts et retraits, puis payer certaines factures comme EDG. NIMBA vous guide en langage naturel, prépare les étapes et sécurise les opérations sensibles avec OTP ou validation renforcée.',
            intent: 'SERVICE_INFO',
            buttons: $this->mergeButtons(
                [
                    $this->button('Vérifier mon solde'),
                    $this->button('Envoyer de l\'argent'),
                    $this->button('Quels sont les frais ?'),
                ],
                $this->billButtons($user),
            ),
            metadata: $this->buildPersonalizedMetadata($user, [
                'summary' => ['solde', 'transfert', 'historique', 'paiements EDG', 'sécurité'],
                'tips' => [
                    'Essayez : Montre-moi mon historique.',
                    'Ou : Comment sécuriser mon compte ?',
                ],
            ]),
        );
    }

    private function handleFeesInfo(User $user): array
    {
        $this->clearState($user);

        return $this->buildResponse(
            reply: 'Les frais dépendent du type d\'opération et du parcours concerné. NIMBA peut vous orienter vers le bon flow, puis l\'application vous affiche toujours les validations utiles avant confirmation. Si vous voulez un détail précis pour votre cas, je peux vous guider vers le transfert, l\'historique ou le support.',
            intent: 'FEES_INFO',
            buttons: [
                $this->button('Envoyer de l\'argent'),
                $this->button('Historique des transactions'),
                $this->button('Support client'),
            ],
            metadata: $this->buildPersonalizedMetadata($user, [
                'tips' => [
                    'Posez par exemple : Envoyer 25000 à 622000000.',
                    'Ou : Montre-moi mes dernières transactions.',
                ],
            ]),
        );
    }

    private function handleThanks(User $user): array
    {
        return $this->buildResponse(
            reply: 'Avec plaisir. Si vous voulez, je peux continuer avec votre solde, un transfert, vos factures EDG ou le support.',
            intent: 'THANKS',
            buttons: $this->defaultButtons($user),
            metadata: $this->buildPersonalizedMetadata($user),
        );
    }

    private function handleTrocPhone(User $user): array
    {
        $this->clearState($user);

        $catalog = TrocPhonePrice::query()
            ->orderBy('base_price')
            ->get(['model', 'storage', 'base_price'])
            ->map(fn (TrocPhonePrice $price) => sprintf('%s %s - %s USD', $price->model, $price->storage, number_format((float) $price->base_price, 0, '.', ' ')))
            ->take(5)
            ->values()
            ->toArray();

        return $this->buildResponse(
            reply: 'Passons en mode Troc. Je vais estimer ton téléphone, analyser la photo si tu en ajoutes une, puis calculer la différence pour l\'iPhone cible. Prépare le modèle, le stockage, la batterie et le détail de l\'écran, du dos, du châssis, de la caméra et de Face ID.',
            intent: 'TROC_PHONE',
            buttons: [
                $this->button('Échanger mon téléphone'),
                $this->button('iPhone 11'),
                $this->button('iPhone 12'),
                $this->button('iPhone 13'),
                $this->button('Écran rayé'),
                $this->button('Écran cassé'),
                $this->button('Face ID OK'),
            ],
            metadata: $this->buildPersonalizedMetadata($user, [
                'action' => 'open_troc_flow',
                'summary' => ['modèle', 'stockage', 'batterie', 'écran', 'dos', 'châssis', 'caméra', 'Face ID', 'photo'],
                'tips' => [
                    'Commencez avec votre modèle actuel, par exemple iPhone 12 128GB.',
                    'Ajoutez une photo nette de face et de dos pour aider NIMBA à repérer rayures et casses visibles.',
                    'Renseignez aussi la caméra, le châssis et Face ID pour une estimation plus juste.',
                ],
                'catalog_preview' => $catalog,
                'knowledge_topic' => 'troc',
            ]),
        );
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
            metadata: $this->buildPersonalizedMetadata($user, [
                'action' => 'open_wallet_balance',
                'available_balance' => $available,
                'blocked_amount' => (int) $wallet->blocked_amount,
                'currency' => $wallet->currency ?? 'GNF',
            ]),
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
                buttons: $this->mergeButtons([
                    $this->button('Annuler'),
                ], $this->buildFrequentBeneficiaryButtons($user)),
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
            buttons: $this->mergeButtons([
                $this->button('Annuler'),
            ], $this->buildFrequentBeneficiaryButtons($user)),
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

        $riskAlert = null;
        if (!$this->assistantMemoryService->isFrequentBeneficiary($user, $recipient) && $amount >= 250000) {
            $riskAlert = 'Ce bénéficiaire n\'apparaît pas encore parmi vos contacts fréquents. Vérifiez bien son numéro avant de confirmer.';
        }

        return $this->buildResponse(
            reply: $riskAlert === null
                ? "Confirmez-vous l'envoi de {$this->formatAmount($amount)} GNF au numéro {$recipient->phone} ?"
                : "Confirmez-vous l'envoi de {$this->formatAmount($amount)} GNF au numéro {$recipient->phone} ?\n{$riskAlert}",
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
                'risk_alert' => $riskAlert,
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

            $this->assistantMemoryService->rememberTransferRecipient($user, $recipient, 'app', $amount);

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
                metadata: $this->buildPersonalizedMetadata($user),
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
            metadata: $this->buildPersonalizedMetadata($user, [
                'action' => 'open_transaction_history',
                'transactions_count' => $transactions->count(),
            ]),
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
        $aiAnswer = $this->buildAiFallbackResponse($user, $message, [
            'channel' => 'app',
            'mode' => 'chatbot',
            'memory_summary' => $this->assistantMemoryService->memorySummaries($user, 3),
            'agent_profile' => $this->currentAgentProfile,
        ]);
        if ($aiAnswer !== null) {
            return $aiAnswer;
        }

        $applicationAnswer = $this->buildGenericApplicationKnowledgeResponse($user, $message);
        if ($applicationAnswer !== null) {
            return $applicationAnswer;
        }

        $attempts = ((int) ($state['unknown_attempts'] ?? 0)) + 1;
        $hint = $this->inferIntentHint($message);

        if ($attempts >= self::UNKNOWN_ATTEMPT_THRESHOLD) {
            return $this->handleSupport($user, $message, 'unrecognized_after_retries', ['attempts' => $attempts]);
        }

        $this->putState($user, [
            'session_id' => $this->getSessionId($user),
            'unknown_attempts' => $attempts,
        ]);

        return $this->buildResponse(
            reply: $hint !== null
                ? "Je ne suis pas encore certain de votre demande. {$hint}"
                : 'Je n\'ai pas bien compris. Vous pouvez demander votre solde, un envoi d\'argent, l\'historique, un dépôt, un retrait ou le support.',
            intent: 'UNKNOWN',
            buttons: $this->buildContextualSuggestions($user, $message),
            metadata: $this->buildPersonalizedMetadata($user, [
                'attempts' => $attempts,
                'hint' => $hint,
                'confidence' => 0.24,
                'learning_signal' => 'needs_training',
            ]),
        );
    }

    private function buildAiFallbackResponse(User $user, string $message, array $context = []): ?array
    {
        if (!(bool) config('services.nimba_ai.enable_app_fallback', true)) {
            return null;
        }

        $result = $this->aiAssistantService->answer($user, $message, $this->loadTranscript($user), $context);
        if ($result === null) {
            return null;
        }

        $this->clearState($user);

        return $this->buildResponse(
            reply: (string) ($result['reply'] ?? ''),
            intent: 'AI_FALLBACK',
            buttons: $this->mergeButtons([
                $this->button('Aide'),
                $this->button('Support client'),
            ], $this->billButtons($user)),
            metadata: $this->buildPersonalizedMetadata($user, [
                'knowledge_topic' => 'general_ai',
                'confidence' => 0.66,
                'ai_generated' => true,
                'ai_provider' => $result['provider'] ?? config('services.nimba_ai.provider', 'chatgpt'),
                'ai_model' => $result['model'] ?? null,
                'finish_reason' => $result['finish_reason'] ?? null,
                'knowledge_references' => $result['knowledge_references'] ?? [],
                'web_references' => $result['web_references'] ?? [],
                'web_search_provider' => $result['web_search_provider'] ?? null,
                'selected_agent_key' => $this->currentAgentKey(),
            ]),
        );
    }

    private function resolveKnowledgeResponse(User $user, string $message): ?array
    {
        $knowledge = $this->matchAppKnowledge($message);
        if ($knowledge === null) {
            return null;
        }

        $this->clearState($user);

        return $this->buildResponse(
            reply: (string) ($knowledge['reply'] ?? ''),
            intent: 'APP_KNOWLEDGE',
            buttons: $this->knowledgeButtons($user, $knowledge),
            metadata: $this->buildPersonalizedMetadata($user, array_filter([
                'knowledge_key' => $knowledge['key'] ?? null,
                'knowledge_topic' => $knowledge['knowledge_topic'] ?? 'application',
                'action' => $knowledge['action'] ?? null,
                'confidence' => 0.9,
            ], fn ($value) => $value !== null)),
        );
    }

    private function shouldPreferKnowledgeResponse(string $message, string $intent): bool
    {
        if ($intent === 'UNKNOWN') {
            return true;
        }

        $normalized = $this->normalize($message);
        $isQuestion = $this->matchesAny($normalized, [
            'comment',
            'combien',
            'minimum',
            'pourquoi',
            'quand',
            'ou',
            'explique',
            'c est quoi',
            'ca marche',
        ]);

        if (!$isQuestion) {
            return false;
        }

        return in_array($intent, [
            'SEND_MONEY',
            'DEPOSIT',
            'WITHDRAW',
            'PREPAID_BILL',
            'POSTPAID_BILL',
            'ACCOUNT_INFO',
            'SECURITY_HELP',
            'SERVICE_INFO',
            'FEES_INFO',
        ], true);
    }

    private function buildGenericApplicationKnowledgeResponse(User $user, string $message): ?array
    {
        if (!$this->looksLikeApplicationQuestion($message)) {
            return null;
        }

        $this->clearState($user);

        return $this->buildResponse(
            reply: 'Oui, je peux répondre directement aux questions sur EdgPay. Dans l\'application, je peux vous expliquer le solde, les transferts, l\'historique, les dépôts, les retraits, la sécurité du compte et les parcours EDG prépayé ou postpayé. Posez votre question naturellement et je vous répondrai avec le fonctionnement réel du service.',
            intent: 'APP_KNOWLEDGE',
            buttons: $this->mergeButtons([
                $this->button('Comment fonctionne le service ?'),
                $this->button('Quels sont les frais ?'),
                $this->button('Sécurité du compte'),
            ], $this->defaultButtons($user)),
            metadata: $this->buildPersonalizedMetadata($user, [
                'knowledge_key' => 'application_capabilities',
                'knowledge_topic' => 'application',
                'confidence' => 0.72,
            ]),
        );
    }

    private function detectIntent(string $message): string
    {
        $normalized = $this->normalize($message);

        if ($this->matchesConfiguredIntent($normalized, 'greeting')) {
            return 'GREETING';
        }

        if ($this->matchesConfiguredIntent($normalized, 'help')) {
            return 'HELP';
        }

        if ($this->matchesConfiguredIntent($normalized, 'thanks')) {
            return 'THANKS';
        }

        if ($this->matchesConfiguredIntent($normalized, 'service_info')) {
            return 'SERVICE_INFO';
        }

        if ($this->matchesConfiguredIntent($normalized, 'fees_info')) {
            return 'FEES_INFO';
        }

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

        if ($this->matchesConfiguredIntent($normalized, 'troc_phone')) {
            return 'TROC_PHONE';
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

        if (preg_match('/(?:a|à)\s+([A-Za-zÀ-ÿ\-\']{2,}(?:\s+[A-Za-zÀ-ÿ\-\']{2,}){0,2})/u', $clean, $nameMatch)) {
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
                $this->button('Aide'),
                $this->button('Envoyer de l\'argent'),
                $this->button('Vérifier mon solde'),
                $this->button('Historique des transactions'),
                $this->button('Dépôt'),
                $this->button('Retrait'),
                $this->button('Échanger mon téléphone'),
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
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

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

    private function buildContextualSuggestions(User $user, string $message): array
    {
        $normalized = $this->normalize($message);
        $suggestions = [];

        if (preg_match('/\d{4,9}/', $message)) {
            $suggestions[] = $this->button('Envoyer de l\'argent');
        }

        if ($this->matchesAny($normalized, ['facture', 'edg', 'courant', 'compteur'])) {
            $suggestions[] = $this->button('Facture prepayee');
            $suggestions[] = $this->button('Facture postpayee');
        }

        if ($this->matchesAny($normalized, ['otp', 'pin', 'securite', 'code'])) {
            $suggestions[] = $this->button('Sécurité du compte');
        }

        if ($this->matchesAny($normalized, ['frais', 'tarif', 'commission', 'cout'])) {
            $suggestions[] = $this->button('Quels sont les frais ?');
        }

        if ($this->matchesAny($normalized, ['comment', 'fonctionne', 'service', 'edgpay', 'nimba'])) {
            $suggestions[] = $this->button('Comment fonctionne le service ?');
        }

        $suggestions[] = $this->button('Aide');

        return $this->mergeButtons(
            $suggestions,
            $this->buildFrequentBeneficiaryButtons($user),
            $this->defaultButtons($user),
        );
    }

    private function inferIntentHint(string $message): ?string
    {
        $normalized = $this->normalize($message);

        if (preg_match('/\d{4,9}/', $message) && $this->matchesAny($normalized, ['envoyer', 'transfert', 'a'])) {
            return 'Si vous voulez transférer, essayez une phrase comme : Envoyer 25000 à 622000000.';
        }

        if ($this->matchesAny($normalized, ['facture', 'edg', 'courant', 'compteur'])) {
            return 'Si vous voulez payer EDG, vous pouvez choisir Facture prepayee ou Facture postpayee.';
        }

        if ($this->matchesAny($normalized, ['solde', 'balance'])) {
            return 'Essayez par exemple : Quel est mon solde ?';
        }

        if ($this->matchesAny($normalized, ['frais', 'tarif', 'commission'])) {
            return 'Vous pouvez demander simplement : Quels sont les frais ?';
        }

        if ($this->matchesAny($normalized, ['comment', 'service', 'fonctionne'])) {
            return 'Essayez par exemple : Comment fonctionne le service ?';
        }

        return 'Vous pouvez aussi me dire simplement : aide.';
    }

    private function buildCapabilitySummary(User $user): array
    {
        $items = ['solde', 'transfert', 'historique', 'depot', 'retrait', 'support', 'frais'];

        if ($this->supportsBillPayments($user)) {
            $items[] = 'factures EDG';
        }

        return $items;
    }

    private function describeRecentActivity(User $user): string
    {
        $latest = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first(['type', 'amount', 'created_at', 'description', 'metadata']);

        if (!$latest) {
            return 'Aucune opération récente détectée.';
        }

        $amount = $this->formatAmount(abs((int) $latest->amount));
        $date = Carbon::parse($latest->created_at)->format('d/m H:i');
        $label = $this->describeTransactionLabel($latest);

        return "Dernière opération: {$label} de {$amount} GNF le {$date}.";
    }

    private function buildPersonalizedMetadata(User $user, array $metadata = []): array
    {
        $suggestions = $this->buildPersonalizedSuggestions($user);
        $smartSuggestions = $this->buildSmartSuggestions($user);
        $memorySummary = $this->assistantMemoryService->memorySummaries($user, 3);
        $automationSuggestions = $this->assistantMemoryService->automationSuggestions($user, 3);

        if (!empty($suggestions) && !array_key_exists('personalized_suggestions', $metadata)) {
            $metadata['personalized_suggestions'] = $suggestions;
        }

        if (!empty($smartSuggestions) && !array_key_exists('smart_suggestions', $metadata)) {
            $metadata['smart_suggestions'] = $smartSuggestions;
        }

        if (!empty($memorySummary) && !array_key_exists('memory_summary', $metadata)) {
            $metadata['memory_summary'] = $memorySummary;
        }

        if (!empty($automationSuggestions) && !array_key_exists('automation_suggestions', $metadata)) {
            $metadata['automation_suggestions'] = $automationSuggestions;
        }

        return $metadata;
    }

    private function buildSmartSuggestions(User $user): array
    {
        return $this->buildFrequentBeneficiaryButtons($user);
    }

    private function buildFrequentBeneficiaryButtons(User $user): array
    {
        $memoryButtons = collect($this->assistantMemoryService->frequentBeneficiaries($user, 2))
            ->map(fn (array $beneficiary): array => [
                'label' => 'Envoyer à ' . $beneficiary['display_name'],
                'value' => "Envoyer de l'argent à {$beneficiary['display_name']}",
            ])
            ->all();

        if (!empty($memoryButtons)) {
            return $memoryButtons;
        }

        $recipientCounts = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'transfer_out')
            ->latest('created_at')
            ->limit(20)
            ->get(['metadata'])
            ->map(fn (WalletTransaction $transaction): ?string => Arr::get($transaction->metadata ?? [], 'to_user_id'))
            ->filter()
            ->countBy()
            ->filter(fn (int $count): bool => $count >= 2)
            ->sortDesc();

        if ($recipientCounts->isEmpty()) {
            return [];
        }

        $recipients = User::query()
            ->whereIn('id', $recipientCounts->keys()->all())
            ->get(['id', 'display_name', 'phone'])
            ->keyBy('id');

        $buttons = [];
        foreach ($recipientCounts->take(2) as $recipientId => $count) {
            $recipient = $recipients->get($recipientId);
            if (!$recipient) {
                continue;
            }

            $label = 'Envoyer à ' . $recipient->display_name;
            $buttons[] = [
                'label' => $label,
                'value' => "Envoyer de l'argent à {$recipient->display_name}",
            ];
        }

        return $buttons;
    }

    private function updateLongTermMemory(User $user, array $response): void
    {
        $intent = (string) ($response['intent'] ?? 'UNKNOWN');
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];

        $topic = Arr::get($metadata, 'knowledge_topic');
        if (is_string($topic) && $topic !== '') {
            $this->assistantMemoryService->rememberKnowledgeTopic($user, $topic, 'app');
        }

        if ($intent === 'UNKNOWN') {
            $hint = Arr::get($metadata, 'hint');
            $message = is_string($hint) && $hint !== '' ? $hint : 'unknown';
            $this->assistantMemoryService->rememberUnknownMessage($user, $message, 'app');
        }
    }

    private function analyzeIncomingMessage(string $message, array $entities, array $state): array
    {
        $intentGuess = ($state['awaiting'] ?? null) !== null
            ? (string) ($state['intent'] ?? 'STATEFUL_FLOW')
            : $this->detectIntent($message);

        $knowledgeTopic = $this->detectKnowledgeTopic($message, $intentGuess);

        return [
            'intent_guess' => $intentGuess,
            'confidence' => $this->estimateIntentConfidence($intentGuess, $entities, $state),
            'knowledge_topic' => $knowledgeTopic,
            'learning_signal' => $intentGuess === 'UNKNOWN' ? 'review_required' : 'understood',
        ];
    }

    private function decorateResponseMetadata(User $user, array $response, array $analysis): array
    {
        $metadata = $response['metadata'] ?? [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        if (!array_key_exists('confidence', $metadata)) {
            $metadata['confidence'] = $analysis['confidence'] ?? 0.5;
        }

        if (!array_key_exists('knowledge_topic', $metadata) && ($analysis['knowledge_topic'] ?? null) !== null) {
            $metadata['knowledge_topic'] = $analysis['knowledge_topic'];
        }

        if (!array_key_exists('learning_signal', $metadata)) {
            $metadata['learning_signal'] = ($response['intent'] ?? 'UNKNOWN') === 'UNKNOWN'
                ? 'needs_training'
                : 'handled';
        }

        $metadata['selected_agent'] = $this->agentMetadataPayload();
        $metadata['available_agents'] = $this->availableAgentPayloads();

        if (($response['intent'] ?? null) === 'UNKNOWN') {
            return $this->buildPersonalizedMetadata($user, $metadata);
        }

        return $metadata;
    }

    private function detectKnowledgeTopic(string $message, string $intentGuess): ?string
    {
        if ($intentGuess === 'APP_KNOWLEDGE') {
            $knowledge = $this->matchAppKnowledge($message);
            if ($knowledge !== null) {
                return (string) ($knowledge['knowledge_topic'] ?? 'application');
            }

            return 'application';
        }

        if ($intentGuess === 'FEES_INFO') {
            return 'fees';
        }

        if ($intentGuess === 'SECURITY_HELP') {
            return 'security';
        }

        if ($intentGuess === 'SERVICE_INFO') {
            return 'service';
        }

        $normalized = $this->normalize($message);

        return match (true) {
            $this->matchesAny($normalized, ['frais', 'tarif', 'commission']) => 'fees',
            $this->matchesAny($normalized, ['otp', 'pin', 'fraude', 'securite']) => 'security',
            $this->matchesAny($normalized, ['comment', 'fonctionne', 'service', 'edgpay', 'nimba']) => 'service',
            $this->looksLikeApplicationQuestion($message) => 'application',
            default => null,
        };
    }

    private function estimateIntentConfidence(string $intent, array $entities, array $state): float
    {
        if (($state['awaiting'] ?? null) !== null) {
            return 0.96;
        }

        return match ($intent) {
            'UNKNOWN' => 0.24,
            'AI_FALLBACK' => 0.66,
            'SEND_MONEY' => isset($entities['amount']) && (isset($entities['phone']) || isset($entities['recipient_name'])) ? 0.93 : 0.76,
            'APP_KNOWLEDGE' => 0.9,
            'GREETING', 'HELP', 'THANKS', 'SERVICE_INFO', 'FEES_INFO', 'SECURITY_HELP' => 0.91,
            default => 0.84,
        };
    }

    private function matchAppKnowledge(string $message): ?array
    {
        $normalized = $this->normalize($message);
        $knowledgeEntries = $this->getAppKnowledgeEntries();

        if (!is_array($knowledgeEntries)) {
            return null;
        }

        foreach ($knowledgeEntries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $patterns = $entry['patterns'] ?? [];
            if (!$this->matchesAny($normalized, is_array($patterns) ? $patterns : [])) {
                continue;
            }

            $entry['key'] = $key;

            if ($key === 'postpaid_minimum') {
                $entry['reply'] = $this->buildPostpaidMinimumKnowledgeReply();
            }

            return $entry;
        }

        return null;
    }

    private function getAppKnowledgeEntries(): array
    {
        $knowledgeEntries = config('chatbot.app_knowledge', []);
        if (!is_array($knowledgeEntries)) {
            return [];
        }

        foreach (array_keys($knowledgeEntries) as $key) {
            $override = $this->getAppKnowledgeOverride($key);
            if ($override === null) {
                continue;
            }

            $knowledgeEntries[$key] = array_merge($knowledgeEntries[$key], $override);
        }

        return $knowledgeEntries;
    }

    private function resolveConversationalAgent(?string $requestedKey, ?User $user = null): array
    {
        $agents = $this->configuredConversationalAgents();
        if (!is_array($agents) || empty($agents)) {
            return [
                'key' => 'nimba',
                'label' => 'NIMBA Classique',
                'description' => 'Assistant EdgPay',
                'system_prompt' => 'Style d agent: assistant polyvalent.',
                'provider' => '',
                'model' => '',
                'is_default' => true,
            ];
        }

        $normalizedKey = $this->normalizeAgentKey($requestedKey);
        if ($normalizedKey === '' && $user !== null) {
            $normalizedKey = $this->normalizeAgentKey($user->default_conversational_agent ?? null);
        }

        $defaultKey = $this->defaultConversationalAgentKey($agents);

        if ($normalizedKey !== '' && isset($agents[$normalizedKey]) && is_array($agents[$normalizedKey])) {
            return array_merge($agents[$normalizedKey], [
                'key' => $normalizedKey,
                'is_default' => $normalizedKey === $defaultKey,
            ]);
        }

        $defaultAgent = is_array($agents[$defaultKey] ?? null) ? $agents[$defaultKey] : [];

        return array_merge($defaultAgent, [
            'key' => $defaultKey,
            'is_default' => true,
        ]);
    }

    private function currentAgentKey(): string
    {
        return (string) ($this->currentAgentProfile['key'] ?? 'nimba');
    }

    private function currentAgentLabel(): string
    {
        return (string) ($this->currentAgentProfile['label'] ?? 'NIMBA Classique');
    }

    private function agentMetadataPayload(): array
    {
        return [
            'key' => $this->currentAgentKey(),
            'label' => $this->currentAgentLabel(),
            'description' => (string) ($this->currentAgentProfile['description'] ?? ''),
            'provider' => (string) ($this->currentAgentProfile['provider'] ?? ''),
            'model' => (string) ($this->currentAgentProfile['model'] ?? ''),
            'is_default' => (bool) ($this->currentAgentProfile['is_default'] ?? false),
        ];
    }

    private function availableAgentPayloads(): array
    {
        $agents = $this->configuredConversationalAgents();
        if (!is_array($agents)) {
            return [$this->agentMetadataPayload()];
        }

        $selectedKey = $this->currentAgentKey();

        return collect($agents)
            ->map(function ($agent, $key) use ($selectedKey): ?array {
                if (!is_array($agent)) {
                    return null;
                }

                return [
                    'key' => (string) $key,
                    'label' => (string) ($agent['label'] ?? $key),
                    'description' => (string) ($agent['description'] ?? ''),
                    'provider' => (string) ($agent['provider'] ?? ''),
                    'model' => (string) ($agent['model'] ?? ''),
                    'is_selected' => (string) $key === $selectedKey,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function configuredConversationalAgents(): array
    {
        $configured = config('chatbot.conversational_agents', []);
        $override = SystemSetting::query()
            ->where('key', 'chatbot_conversational_agents')
            ->where('is_active', true)
            ->first();

        $overrideValue = $override?->formatted_value;
        if (is_array($overrideValue) && !empty($overrideValue)) {
            $normalized = $this->normalizeConfiguredAgents($overrideValue);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        return is_array($configured) ? $configured : [];
    }

    private function normalizeConfiguredAgents(array $rawAgents): array
    {
        $normalized = [];

        $isAssoc = Arr::isAssoc($rawAgents);
        foreach ($rawAgents as $key => $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $agentKey = $this->normalizeAgentKey($isAssoc ? $key : ($agent['key'] ?? null));
            if ($agentKey === '') {
                continue;
            }

            $label = trim((string) ($agent['label'] ?? ''));
            if ($label === '') {
                $label = strtoupper($agentKey);
            }

            $normalized[$agentKey] = [
                'label' => $label,
                'description' => trim((string) ($agent['description'] ?? '')),
                'system_prompt' => trim((string) ($agent['system_prompt'] ?? 'Style d agent: assistant EdgPay.')),
                'provider' => trim(strtolower((string) ($agent['provider'] ?? ''))),
                'model' => trim((string) ($agent['model'] ?? '')),
            ];
        }

        return $normalized;
    }

    private function defaultConversationalAgentKey(array $agents): string
    {
        $setting = SystemSetting::query()
            ->where('key', 'chatbot_default_conversational_agent')
            ->where('is_active', true)
            ->first();

        $configuredDefault = $this->normalizeAgentKey($setting?->formatted_value ?? $setting?->value ?? null);
        if ($configuredDefault !== '' && isset($agents[$configuredDefault])) {
            return $configuredDefault;
        }

        if (isset($agents['nimba'])) {
            return 'nimba';
        }

        $firstKey = array_key_first($agents);

        return is_string($firstKey) ? $firstKey : 'nimba';
    }

    private function normalizeAgentKey(mixed $value): string
    {
        return trim(strtolower((string) ($value ?? '')));
    }

    private function getAppKnowledgeOverride(string $key): ?array
    {
        $setting = SystemSetting::query()
            ->where('key', "chatbot_app_knowledge_{$key}")
            ->where('is_active', true)
            ->first();

        if (!$setting) {
            return null;
        }

        $value = $setting->formatted_value;
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($setting->value) || trim($setting->value) === '') {
            return null;
        }

        $decoded = json_decode($setting->value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function knowledgeButtons(User $user, array $knowledge): array
    {
        $labels = $knowledge['buttons'] ?? [];
        $buttons = [];

        if (is_array($labels)) {
            foreach ($labels as $label) {
                if (!is_string($label) || trim($label) === '') {
                    continue;
                }

                $buttons[] = $this->button($label);
            }
        }

        return $this->mergeButtons($buttons, $this->billButtons($user));
    }

    private function buildPostpaidMinimumKnowledgeReply(): string
    {
        return 'Pour une facture EDG postpayée, il n\'y a pas un minimum fixe universel dans EdgPay. Le montant à régler dépend du restant dû de la créance affichée dans le parcours postpayé. Côté métier, le paiement est plafonné au montant restant, et s\'il ne reste rien à payer, aucun règlement n\'est nécessaire.';
    }

    private function looksLikeApplicationQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);

        return $this->matchesAny($normalized, [
            'application',
            'appli',
            'app',
            'edgpay',
            'nimba',
            'compte',
            'portefeuille',
            'wallet',
            'solde',
            'transfert',
            'envoyer',
            'historique',
            'transaction',
            'depot',
            'retrait',
            'facture',
            'edg',
            'prepay',
            'postpay',
            'otp',
            'support',
            'frais',
            'securite',
        ]);
    }

    private function buildPersonalizedSuggestions(User $user): array
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(6)
            ->get(['type', 'description', 'metadata']);

        if ($transactions->isEmpty()) {
            return [];
        }

        $suggestions = [];

        foreach ($transactions as $transaction) {
            $suggestion = $this->suggestionForTransaction($transaction, $user);
            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        if ($transactions->count() >= 3) {
            $suggestions[] = 'Historique des transactions';
        }

        return array_values(array_slice(array_unique($suggestions), 0, 4));
    }

    private function suggestionForTransaction(WalletTransaction $transaction, User $user): ?string
    {
        if ($this->supportsBillPayments($user)) {
            if ($this->looksLikePrepaidBillTransaction($transaction)) {
                return 'Facture prepayee';
            }

            if ($this->looksLikePostpaidBillTransaction($transaction)) {
                return 'Facture postpayee';
            }
        }

        $type = (string) $transaction->type;

        return match (true) {
            str_starts_with($type, 'transfer_') => 'Envoyer de l\'argent',
            str_contains($type, 'withdraw') => 'Retrait',
            str_contains($type, 'deposit'), str_contains($type, 'recharge'), str_starts_with($type, 'credit_') => 'Dépôt',
            default => null,
        };
    }

    private function describeTransactionLabel(WalletTransaction $transaction): string
    {
        $type = (string) $transaction->type;

        if ($this->looksLikePrepaidBillTransaction($transaction)) {
            return 'paiement EDG prépayé';
        }

        if ($this->looksLikePostpaidBillTransaction($transaction)) {
            return 'paiement EDG postpayé';
        }

        return match (true) {
            $type === 'transfer_out' => 'transfert envoyé',
            $type === 'transfer_in' => 'transfert reçu',
            str_contains($type, 'withdraw') => 'retrait',
            str_contains($type, 'deposit'), str_contains($type, 'recharge'), str_starts_with($type, 'credit_') => 'dépôt',
            default => $type,
        };
    }

    private function looksLikePrepaidBillTransaction(WalletTransaction $transaction): bool
    {
        $haystack = $this->transactionSearchableText($transaction);

        return $this->matchesAny($haystack, [
            'prepay',
            'prepayee',
            'prepaye',
            'achat courant',
            'achat de courant',
            'token edg',
            'compteur prepaid',
            'compteur prepaye',
        ]);
    }

    private function looksLikePostpaidBillTransaction(WalletTransaction $transaction): bool
    {
        $haystack = $this->transactionSearchableText($transaction);

        return $this->matchesAny($haystack, [
            'postpay',
            'postpayee',
            'postpaye',
            'creance',
            'paiement facture',
            'facture edg',
            'compteur postpaid',
        ]);
    }

    private function looksLikeBillTransaction(WalletTransaction $transaction): bool
    {
        $haystack = $this->transactionSearchableText($transaction);

        return $this->matchesAny($haystack, ['edg', 'facture', 'courant', 'compteur', 'prepay', 'postpay']);
    }

    private function transactionSearchableText(WalletTransaction $transaction): string
    {
        $metadata = $transaction->metadata;
        $metadataString = is_array($metadata) ? json_encode($metadata) : '';

        return $this->normalize(trim(sprintf(
            '%s %s %s',
            (string) $transaction->type,
            (string) ($transaction->description ?? ''),
            (string) $metadataString,
        )));
    }

    private function timeBasedGreeting(): string
    {
        $hour = (int) now()->format('H');

        return match (true) {
            $hour < 12 => 'Bonjour',
            $hour < 18 => 'Bon après-midi',
            default => 'Bonsoir',
        };
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