<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppChatSession;
use Carbon\Carbon;

class WhatsAppConversationStateService
{
    public function getOrCreate(string $phone): WhatsAppChatSession
    {
        return WhatsAppChatSession::query()->firstOrCreate(
            ['user_phone' => $phone],
            [
                'state' => 'awaiting_menu_choice',
                'context' => [],
                'last_interaction_at' => now(),
            ]
        );
    }

    public function updateState(
        WhatsAppChatSession $session,
        string $state,
        array $context = [],
        ?string $lastMessage = null,
    ): WhatsAppChatSession {
        $session->fill([
            'state' => $state,
            'context' => $context,
            'last_message' => $lastMessage,
            'last_interaction_at' => Carbon::now(),
        ])->save();

        return $session->refresh();
    }

    public function mergeContext(WhatsAppChatSession $session, array $context): WhatsAppChatSession
    {
        $merged = array_merge($session->context ?? [], $context);

        $session->fill([
            'context' => $merged,
            'last_interaction_at' => Carbon::now(),
        ])->save();

        return $session->refresh();
    }

    public function clear(WhatsAppChatSession $session): WhatsAppChatSession
    {
        return $this->updateState($session, 'idle', []);
    }
}
