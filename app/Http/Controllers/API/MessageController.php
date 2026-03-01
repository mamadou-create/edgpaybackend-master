<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\MessageRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    private MessageRepositoryInterface $messageRepository;

    public function __construct(MessageRepositoryInterface $messageRepository)
    {
        $this->messageRepository = $messageRepository;
    }

    /**
     * 👥 Récupérer les utilisateurs disponibles pour la messagerie
     */
    public function getUsers(Request $request): JsonResponse
    {
        try {
            $currentUserId = Auth::id();

            // Récupérer les paramètres de pagination et recherche
            $search = $request->input('search');
            $limit = $request->input('limit', 50);
            $offset = $request->input('offset', 0);

            // Valider les paramètres
            if (!is_numeric($limit) || $limit <= 0) {
                $limit = 50;
            }

            if (!is_numeric($offset) || $offset < 0) {
                $offset = 0;
            }

            $users = $this->messageRepository->getUsersForMessaging(
                userId: $currentUserId,
                search: $search,
                limit: $limit,
                offset: $offset
            );

            // Pour le debug
            Log::info('Users fetched for messaging', [
                'count' => count($users),
                'current_user_id' => $currentUserId,
                'search' => $search
            ]);

            return ApiResponseClass::sendResponse(
                $users,
                'Utilisateurs récupérés avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return ApiResponseClass::serverError('Erreur lors de la récupération des utilisateurs: ' . $e->getMessage());
        }
    }

    public function getAllConversations(Request $request): JsonResponse
    {
        try {
            $currentUserId = Auth::id();
            $conversations = $this->messageRepository->getAllUserConversations($currentUserId);

            return ApiResponseClass::sendResponse(
                $conversations,
                'Conversations récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des conversations: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des conversations');
        }
    }

    /**
     * 📨 Récupérer les messages d'une conversation
     */
    public function getConversation(Request $request, string $userId): JsonResponse
    {
        try {
            $currentUserId = Auth::id();

            // Vérifier que l'utilisateur ne cherche pas à parler avec lui-même
            if ($currentUserId === $userId) {
                return ApiResponseClass::sendResponse(
                    [],
                    'Impossible de récupérer une conversation avec vous-même'
                );
            }

            $messages = $this->messageRepository->getConversation($currentUserId, $userId);

            return ApiResponseClass::sendResponse(
                MessageResource::collection($messages),
                'Conversation récupérée avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération de la conversation: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération de la conversation');
        }
    }

    /**
     * ✉️ Envoyer un message
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'receiver_id' => 'required|uuid|exists:users,id',
            'content' => 'required|string|max:5000'
        ]);

        DB::beginTransaction();
        try {
            $message = $this->messageRepository->create([
                'sender_id' => Auth::id(),
                'receiver_id' => $request->receiver_id,
                'content' => $request->content
            ]);

            // Charger les relations
            $message->load(['sender:id,display_name', 'receiver:id,display_name']);

            // Diffuser l'événement
            broadcast(new MessageSent($message))->toOthers();


            Log::info('Message broadcasté', [
                'message_id' => $message->id,
                'channel' => 'chat.' . min($message->sender_id, $message->receiver_id) . '.' . max($message->sender_id, $message->receiver_id),
            ]);

            DB::commit();

            return ApiResponseClass::sendResponse(
                new MessageResource($message),
                'Message envoyé avec succès',
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de l\'envoi du message: ' . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de l\'envoi du message');
        }
    }

    /**
     * ✅ Marquer un message comme lu
     */
    public function markAsRead(string $messageId): JsonResponse
    {
        DB::beginTransaction();
        try {
            $message = $this->messageRepository->getById($messageId);

            if (!$message) {
                return ApiResponseClass::notFound('Message non trouvé');
            }

            if ($message->receiver_id !== Auth::id()) {
                return ApiResponseClass::unauthorized('Vous n\'êtes pas autorisé à marquer ce message comme lu');
            }

            $this->messageRepository->markAsRead($messageId);

            DB::commit();

            return ApiResponseClass::sendResponse(
                null,
                'Message marqué comme lu'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors du marquage du message comme lu: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors du marquage du message comme lu');
        }
    }

    /**
     * ✅ Marquer toute une conversation comme lue
     */
    public function markConversationAsRead(string $userId): JsonResponse
    {
        try {
            $currentUserId = Auth::id();

            // Marquer tous les messages non lus de cet expéditeur comme lus
            $updated = $this->messageRepository->markConversationAsRead($currentUserId, $userId);

            return ApiResponseClass::sendResponse(
                ['marked_count' => $updated],
                'Conversation marquée comme lue avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors du marquage de la conversation comme lue: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors du marquage de la conversation comme lue');
        }
    }



    /**
     * 📊 Récupérer le nombre de messages non lus
     */
    public function getUnreadCount(): JsonResponse
    {
        try {
            $count = $this->messageRepository->getUnreadCount(Auth::id());

            return ApiResponseClass::sendResponse(
                ['count' => $count],
                'Nombre de messages non lus récupéré'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du nombre de messages non lus: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération du nombre de messages non lus');
        }
    }

    /**
     * 👥 Récupérer les conversations récentes
     */
    public function getRecentConversations(): JsonResponse
    {
        try {
            $conversations = $this->messageRepository->getRecentConversations(Auth::id());

            return ApiResponseClass::sendResponse(
                MessageResource::collection($conversations),
                'Conversations récentes récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des conversations récentes: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des conversations récentes');
        }
    }
}
