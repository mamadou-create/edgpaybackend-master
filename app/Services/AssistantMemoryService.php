<?php

namespace App\Services;

use App\Models\ChatHistory;
use App\Models\User;
use App\Models\UserAssistantMemory;
use App\Models\WalletTransaction;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AssistantMemoryService
{
    private ?bool $memoryTableAvailable = null;

    public function rememberTransferRecipient(User $user, User $recipient, string $channel, int $amount): void
    {
        if (!$this->isMemoryTableAvailable()) {
            return;
        }

        $memory = UserAssistantMemory::query()->firstOrNew([
            'user_id' => $user->id,
            'category' => 'frequent_beneficiary',
            'memory_key' => $recipient->id,
        ]);

        $payload = $memory->payload ?? [];

        $memory->summary = 'Destinataire fréquent : ' . $recipient->display_name;
        $memory->payload = array_merge($payload, [
            'recipient_id' => $recipient->id,
            'display_name' => $recipient->display_name,
            'phone' => $recipient->phone,
            'last_amount' => $amount,
            'last_channel' => $channel,
        ]);
        $memory->usage_count = (int) ($memory->usage_count ?? 0) + 1;
        $memory->last_used_at = now();
        $memory->save();
    }

    public function rememberKnowledgeTopic(User $user, string $topic, string $channel): void
    {
        if (!$this->isMemoryTableAvailable()) {
            return;
        }

        $memory = UserAssistantMemory::query()->firstOrNew([
            'user_id' => $user->id,
            'category' => 'knowledge_topic',
            'memory_key' => $topic,
        ]);

        $payload = $memory->payload ?? [];
        $channels = array_values(array_unique(array_merge($payload['channels'] ?? [], [$channel])));

        $memory->summary = 'Sujet d\'intérêt : ' . $topic;
        $memory->payload = [
            'topic' => $topic,
            'channels' => $channels,
        ];
        $memory->usage_count = (int) ($memory->usage_count ?? 0) + 1;
        $memory->last_used_at = now();
        $memory->save();
    }

    public function rememberUnknownMessage(User $user, string $message, string $channel): void
    {
        if (!$this->isMemoryTableAvailable()) {
            return;
        }

        $memory = UserAssistantMemory::query()->firstOrNew([
            'user_id' => $user->id,
            'category' => 'unknown_request',
            'memory_key' => md5(mb_strtolower(trim($message), 'UTF-8')),
        ]);

        $payload = $memory->payload ?? [];

        $memory->summary = 'Demande mal comprise';
        $memory->payload = array_merge($payload, [
            'message' => $message,
            'last_channel' => $channel,
        ]);
        $memory->usage_count = (int) ($memory->usage_count ?? 0) + 1;
        $memory->last_used_at = now();
        $memory->save();
    }

    public function frequentBeneficiaries(User $user, int $limit = 2): array
    {
        if (!$this->isMemoryTableAvailable()) {
            return [];
        }

        return UserAssistantMemory::query()
            ->where('user_id', $user->id)
            ->where('category', 'frequent_beneficiary')
            ->orderByDesc('usage_count')
            ->orderByDesc('last_used_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(function (UserAssistantMemory $memory): array {
                $payload = $memory->payload ?? [];

                return [
                    'recipient_id' => Arr::get($payload, 'recipient_id'),
                    'display_name' => (string) Arr::get($payload, 'display_name', 'Bénéficiaire'),
                    'phone' => (string) Arr::get($payload, 'phone', ''),
                    'usage_count' => (int) ($memory->usage_count ?? 0),
                ];
            })
            ->all();
    }

    public function isFrequentBeneficiary(User $user, User $recipient, int $threshold = 2): bool
    {
        if (!$this->isMemoryTableAvailable()) {
            return false;
        }

        $memory = UserAssistantMemory::query()
            ->where('user_id', $user->id)
            ->where('category', 'frequent_beneficiary')
            ->where('memory_key', $recipient->id)
            ->first();

        return (int) ($memory?->usage_count ?? 0) >= $threshold;
    }

    public function memorySummaries(User $user, int $limit = 3): array
    {
        $summaries = [];

        foreach ($this->frequentBeneficiaries($user, $limit) as $beneficiary) {
            $summaries[] = sprintf(
                'Vous envoyez souvent de l\'argent à %s.',
                $beneficiary['display_name']
            );
        }

        foreach ($this->preferredTopics($user, 2) as $topic) {
            $summaries[] = 'Sujet consulté récemment : ' . $topic . '.';
        }

        return array_values(array_slice(array_unique($summaries), 0, $limit));
    }

    public function automationSuggestions(User $user, int $limit = 3): array
    {
        $suggestions = [];

        $beneficiary = $this->frequentBeneficiaries($user, 1)[0] ?? null;
        if ($beneficiary !== null && (int) $beneficiary['usage_count'] >= 2) {
            $suggestions[] = sprintf(
                'Voulez-vous reprendre un transfert vers %s ?',
                $beneficiary['display_name']
            );
        }

        $recentBills = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->where('description', 'like', '%EDG%')
                    ->orWhere('description', 'like', '%facture%')
                    ->orWhere('description', 'like', '%courant%')
                    ->orWhere('type', 'like', '%creance%');
            })
            ->where('created_at', '>=', now()->subDays(45))
            ->count();

        if ($recentBills >= 2) {
            $suggestions[] = 'Vous payez souvent EDG. Voulez-vous programmer un rappel ?';
        }

        $recentTransfers = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'transfer_out')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($recentTransfers >= 3) {
            $suggestions[] = 'Vous avez plusieurs transferts récents. Voulez-vous revoir vos derniers envois ?';
        }

        return array_values(array_slice(array_unique($suggestions), 0, $limit));
    }

    public function preferredTopics(User $user, int $limit = 3): array
    {
        if (!$this->isMemoryTableAvailable()) {
            return [];
        }

        return UserAssistantMemory::query()
            ->where('user_id', $user->id)
            ->where('category', 'knowledge_topic')
            ->orderByDesc('usage_count')
            ->orderByDesc('last_used_at')
            ->limit(max(1, $limit))
            ->pluck('memory_key')
            ->filter()
            ->values()
            ->all();
    }

    public function misunderstoodTopics(User $user, int $limit = 5): array
    {
        if (!$this->isMemoryTableAvailable()) {
            return [];
        }

        return UserAssistantMemory::query()
            ->where('user_id', $user->id)
            ->where('category', 'unknown_request')
            ->orderByDesc('usage_count')
            ->orderByDesc('last_used_at')
            ->limit(max(1, $limit))
            ->get(['summary', 'payload', 'usage_count'])
            ->map(fn (UserAssistantMemory $memory): array => [
                'message' => (string) Arr::get($memory->payload ?? [], 'message', ''),
                'usage_count' => (int) ($memory->usage_count ?? 0),
            ])
            ->all();
    }

    public function learnFromHistory(User $user): void
    {
        if (!$this->isMemoryTableAvailable()) {
            return;
        }

        $topics = ChatHistory::query()
            ->where('user_id', $user->id)
            ->whereNotNull('metadata->knowledge_topic')
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($topics as $history) {
            $topic = Arr::get($history->metadata ?? [], 'knowledge_topic');
            if (is_string($topic) && $topic !== '') {
                $this->rememberKnowledgeTopic($user, $topic, 'chatbot');
            }
        }
    }

    private function isMemoryTableAvailable(): bool
    {
        if ($this->memoryTableAvailable !== null) {
            return $this->memoryTableAvailable;
        }

        try {
            $this->memoryTableAvailable = Schema::hasTable('user_assistant_memories');
        } catch (\Throwable $error) {
            Log::warning('assistant_memory.schema_check_failed', [
                'error' => $error->getMessage(),
            ]);
            $this->memoryTableAvailable = false;
        }

        if ($this->memoryTableAvailable === false) {
            Log::warning('assistant_memory.table_missing', [
                'table' => 'user_assistant_memories',
            ]);
        }

        return $this->memoryTableAvailable;
    }
}