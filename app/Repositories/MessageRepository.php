<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Interfaces\MessageRepositoryInterface;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageRepository implements MessageRepositoryInterface
{

    /**
     * 👥 Récupère les utilisateurs disponibles pour la messagerie avec filtres de rôle
     */

    public function getUsersForMessaging(string $userId, ?string $search = null, int $limit = 50, int $offset = 0)
    {
        // Récupérer l'utilisateur courant avec son rôle
        $currentUser = User::find($userId);
        if (!$currentUser) {
            return collect();
        }

        $currentUserId = $currentUser->id;
        $assignedUser = $currentUser->assigned_user;

        // Récupérer les IDs de rôle correspondant aux slugs
        $roleIds = $this->getRoleIds();

        // Déterminer les IDs de rôle visibles selon le rôle de l'utilisateur courant
        $visibleRoleIds = $this->getVisibleRoleIds($currentUser, $roleIds);

        // Si aucun rôle n'est visible, retourner une collection vide
        if (empty($visibleRoleIds)) {
            return collect();
        }

        // Construction de la requête
        $query = User::where('users.id', '!=', $userId)
            ->whereIn('users.role_id', $visibleRoleIds)
            ->select([
                'users.id',
                'users.display_name',
                'users.email',
                'users.role_id',
                'users.is_pro',
                'users.assigned_user',
                // Ajouter le slug du rôle via une sous-requête
                DB::raw("(
                    SELECT slug 
                    FROM roles 
                    WHERE roles.id = users.role_id
                    LIMIT 1
                ) as role_slug"),
                // Sous-requête pour le dernier message
                DB::raw("(
                    SELECT content 
                    FROM messages 
                    WHERE (sender_id = users.id AND receiver_id = ?) 
                       OR (receiver_id = users.id AND sender_id = ?)
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as last_message_content"),
                DB::raw("(
                    SELECT created_at 
                    FROM messages 
                    WHERE (sender_id = users.id AND receiver_id = ?) 
                       OR (receiver_id = users.id AND sender_id = ?)
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as last_message_date"),
                // Compteur des messages non lus
                DB::raw("(
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE receiver_id = ? 
                      AND sender_id = users.id 
                      AND `read` = 0
                ) as unread_count")
            ])
            ->addBinding([$userId, $userId, $userId, $userId, $userId], 'select');

        // Ajouter les conditions spécifiques selon les règles métier
        $this->applyBusinessRules($query, $currentUser, $roleIds);

        // Ajouter la recherche si spécifiée
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.display_name', 'LIKE', "%{$search}%")
                    ->orWhere('users.email', 'LIKE', "%{$search}%");
            });
        }

        // Pagination
        $query->skip($offset)->take($limit);

        // Trier par date du dernier message (plus récent d'abord)
        $query->orderByDesc('last_message_date')
            ->orderBy('users.display_name');

        return $query->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'display_name' => $user->display_name,
                    'email' => $user->email,
                    'role_id' => $user->role_id,
                    'role_slug' => $user->role_slug,
                    'is_pro' => (bool) $user->is_pro,
                    'assigned_user' => $user->assigned_user,
                    'last_message' => $user->last_message_content ? [
                        'content' => $user->last_message_content,
                        'created_at' => $user->last_message_date,
                    ] : null,
                    'unread_count' => (int) $user->unread_count,
                ];
            });
    }


    public function getAllUserConversations(string $userId)
    {
        // Récupérer tous les interlocuteurs distincts de l'utilisateur
        $interlocutors = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->selectRaw('
            CASE 
                WHEN sender_id = ? THEN receiver_id 
                ELSE sender_id 
            END as other_user_id,
            MAX(created_at) as last_message_date
        ', [$userId])
            ->groupBy('other_user_id')
            ->orderBy('last_message_date', 'desc')
            ->get()
            ->pluck('other_user_id');

        // Charger les détails des conversations
        $conversations = collect();

        foreach ($interlocutors as $otherUserId) {
            // Récupérer le dernier message avec cet utilisateur
            $lastMessage = Message::where(function ($query) use ($userId, $otherUserId) {
                $query->where('sender_id', $userId)
                    ->where('receiver_id', $otherUserId);
            })
                ->orWhere(function ($query) use ($userId, $otherUserId) {
                    $query->where('sender_id', $otherUserId)
                        ->where('receiver_id', $userId);
                })
                ->with(['sender:id,display_name', 'receiver:id,display_name'])
                ->orderBy('created_at', 'desc')
                ->first();

            // Compter les messages non lus
            $unreadCount = Message::where('sender_id', $otherUserId)
                ->where('receiver_id', $userId)
                ->where('read', false)
                ->count();

            // Récupérer l'utilisateur interlocuteur
            $otherUser = User::find($otherUserId);

            if ($lastMessage && $otherUser) {
                $conversations->push([
                    'other_user' => [
                        'id' => $otherUser->id,
                        'display_name' => $otherUser->display_name,
                        'email' => $otherUser->email,
                        'role_id' => $otherUser->role_id,
                    ],
                    'last_message' => [
                        'id' => $lastMessage->id,
                        'content' => $lastMessage->content,
                        'created_at' => $lastMessage->created_at,
                        'is_read' => $lastMessage->read,
                    ],
                    'unread_count' => $unreadCount,
                ]);
            }
        }

        return $conversations;
    }


    public function getConversation(string $currentUserId, string $otherUserId)
    {
        Log::info('Recherche conversation', [
            'currentUserId' => $currentUserId,
            'otherUserId' => $otherUserId
        ]);

        $messages = Message::where(function ($query) use ($currentUserId, $otherUserId) {
            $query->where('sender_id', $currentUserId)
                ->where('receiver_id', $otherUserId);
        })
            ->orWhere(function ($query) use ($currentUserId, $otherUserId) {
                $query->where('sender_id', $otherUserId)
                    ->where('receiver_id', $currentUserId);
            })
            ->with(['sender:id,display_name', 'receiver:id,display_name'])
            ->orderBy('created_at', 'asc')
            ->get();

        Log::info('Requête SQL', [
            'sql' => Message::where(function ($query) use ($currentUserId, $otherUserId) {
                $query->where('sender_id', $currentUserId)
                    ->where('receiver_id', $otherUserId);
            })
                ->orWhere(function ($query) use ($currentUserId, $otherUserId) {
                    $query->where('sender_id', $otherUserId)
                        ->where('receiver_id', $currentUserId);
                })->toSql()
        ]);

        return $messages;
    }

    public function create(array $data)
    {
        return Message::create($data);
    }

    public function getById(string $id)
    {
        return Message::find($id);
    }

    public function markAsRead(string $id)
    {
        $message = Message::find($id);
        if ($message) {
            $message->update(['read' => true]);
        }
        return $message;
    }

    public function markConversationAsRead(string $currentUserId, string $senderId)
    {
        return Message::where('sender_id', $senderId)
            ->where('receiver_id', $currentUserId)
            ->where('read', false)
            ->update(['read' => true]);
    }


    public function getUnreadCount(string $userId)
    {
        return Message::where('receiver_id', $userId)
            ->where('read', false)
            ->count();
    }

    public function getRecentConversations(string $userId)
    {
        // Sous-requête pour obtenir le dernier message (par date) pour chaque interlocuteur
        $subQuery = Message::selectRaw('
            GREATEST(sender_id, receiver_id) as user1,
            LEAST(sender_id, receiver_id) as user2,
            MAX(created_at) as max_date
        ')
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                    ->orWhere('receiver_id', $userId);
            })
            ->groupBy(DB::raw('GREATEST(sender_id, receiver_id), LEAST(sender_id, receiver_id)'));

        // Main query: récupérer les messages correspondants à ces dernières dates
        $recentMessages = Message::joinSub($subQuery, 'latest', function ($join) use ($userId) {
            $join->on(DB::raw('GREATEST(messages.sender_id, messages.receiver_id)'), '=', 'latest.user1')
                ->on(DB::raw('LEAST(messages.sender_id, messages.receiver_id)'), '=', 'latest.user2')
                ->on('messages.created_at', '=', 'latest.max_date');
        })
            ->with(['sender:id,display_name', 'receiver:id,display_name'])
            ->where(function ($query) use ($userId) {
                $query->where('messages.sender_id', $userId)
                    ->orWhere('messages.receiver_id', $userId);
            })
            ->orderBy('messages.created_at', 'desc')
            ->get();

        return $recentMessages;
    }


    /**
     * Récupère les IDs de rôle correspondant aux slugs
     */
    private function getRoleIds(): array
    {
        return DB::table('roles')
            ->whereIn('slug', [
                RoleEnum::SUPER_ADMIN,
                RoleEnum::SUPPORT_ADMIN,
                RoleEnum::FINANCE_ADMIN,
                RoleEnum::COMMERCIAL_ADMIN,
                RoleEnum::PRO,
                RoleEnum::CLIENT->value,
                RoleEnum::API_CLIENT->value,
            ])
            ->pluck('id', 'slug')
            ->toArray();
    }

    /**
     * Détermine les IDs de rôle visibles selon l'utilisateur courant
     */
    private function getVisibleRoleIds(User $currentUser, array $roleIds): array
    {
        $currentRoleId = $currentUser->role_id;

        // Obtenir le slug du rôle courant via une requête
        $currentRoleSlug = DB::table('roles')
            ->where('id', $currentRoleId)
            ->value('slug');

        if (!$currentRoleSlug) {
            return [];
        }

        // Règles de visibilité
        switch ($currentRoleSlug) {
            case RoleEnum::CLIENT->value:
                // Client: ne voit que le Super Admin
                return $roleIds[RoleEnum::SUPER_ADMIN] ? [$roleIds[RoleEnum::SUPER_ADMIN]] : [];

            case RoleEnum::PRO:
                // Pro: voit Sous Admin et Super Admin
                return array_filter([
                    $roleIds[RoleEnum::SUPER_ADMIN] ?? null,
                    $roleIds[RoleEnum::SUPPORT_ADMIN] ?? null,
                    $roleIds[RoleEnum::FINANCE_ADMIN] ?? null,
                    $roleIds[RoleEnum::COMMERCIAL_ADMIN] ?? null,
                ]);

            case RoleEnum::SUPPORT_ADMIN:
            case RoleEnum::FINANCE_ADMIN:
            case RoleEnum::COMMERCIAL_ADMIN:
                // Sous Admin: voit Pro et Super Admin
                return array_filter([
                    $roleIds[RoleEnum::SUPER_ADMIN] ?? null,
                    $roleIds[RoleEnum::PRO] ?? null,
                ]);

            case RoleEnum::SUPER_ADMIN:
                // Super Admin: voit tout le monde
                return array_values(array_filter($roleIds));

            case RoleEnum::API_CLIENT->value:
                // API Client: règles spécifiques
                return array_filter([
                    $roleIds[RoleEnum::PRO] ?? null,
                    $roleIds[RoleEnum::CLIENT->value] ?? null,
                    $roleIds[RoleEnum::SUPER_ADMIN] ?? null,
                ]);

            default:
                return [];
        }
    }

    /**
     * Applique les règles métier spécifiques (assigned_user)
     */
    private function applyBusinessRules($query, User $currentUser, array $roleIds): void
    {
        $currentRoleId = $currentUser->role_id;
        $currentUserId = $currentUser->id;
        $assignedUser = $currentUser->assigned_user;

        // Obtenir le slug du rôle courant
        $currentRoleSlug = DB::table('roles')
            ->where('id', $currentRoleId)
            ->value('slug');

        if (!$currentRoleSlug) {
            return;
        }

        // Règles basées sur assigned_user
        switch ($currentRoleSlug) {
            case RoleEnum::PRO:
                // Pro: ne peut voir que le Sous Admin qui l'a créé (si assigned_user existe)
                if ($assignedUser) {
                    $query->where(function ($q) use ($assignedUser, $roleIds) {
                        // Le Super Admin est toujours visible
                        $superAdminId = $roleIds[RoleEnum::SUPER_ADMIN] ?? null;
                        if ($superAdminId) {
                            $q->where('users.role_id', $superAdminId);
                        }

                        // Plus le Sous Admin spécifique qui a créé le Pro
                        $q->orWhere(function ($subQ) use ($assignedUser) {
                            $subQ->where('users.id', $assignedUser)
                                ->whereIn('users.role_id', [
                                    $this->getRoleIdBySlug(RoleEnum::SUPPORT_ADMIN),
                                    $this->getRoleIdBySlug(RoleEnum::FINANCE_ADMIN),
                                    $this->getRoleIdBySlug(RoleEnum::COMMERCIAL_ADMIN),
                                ]);
                        });
                    });
                }
                break;

            case in_array($currentRoleSlug, [
                RoleEnum::SUPPORT_ADMIN,
                RoleEnum::FINANCE_ADMIN,
                RoleEnum::COMMERCIAL_ADMIN
            ]):
                // Sous Admin: ne voit que les Pro qu'il a créés
                $query->where(function ($q) use ($currentUserId, $roleIds) {
                    // Le Super Admin est toujours visible
                    $superAdminId = $roleIds[RoleEnum::SUPER_ADMIN] ?? null;
                    if ($superAdminId) {
                        $q->where('users.role_id', $superAdminId);
                    }

                    // Plus les Pro créés par ce Sous Admin
                    $proRoleId = $roleIds[RoleEnum::PRO] ?? null;
                    if ($proRoleId) {
                        $q->orWhere(function ($subQ) use ($currentUserId, $proRoleId) {
                            $subQ->where('users.role_id', $proRoleId)
                                ->where('users.assigned_user', $currentUserId);
                        });
                    }
                });
                break;

            case RoleEnum::CLIENT->value:
                // Client: ne voit que le Super Admin (déjà géré par visibleRoleIds)
                break;

            case RoleEnum::SUPER_ADMIN:
                // Super Admin: voit tout le monde (pas de restrictions supplémentaires)
                break;
        }
    }

    /**
     * Récupère l'ID d'un rôle par son slug
     */
    private function getRoleIdBySlug(string $slug): ?int
    {
        return DB::table('roles')
            ->where('slug', $slug)
            ->value('id');
    }

    /**
     * Récupère le slug d'un rôle par son ID
     */
    private function getRoleSlugById(int $roleId): ?string
    {
        return DB::table('roles')
            ->where('id', $roleId)
            ->value('slug');
    }
}
