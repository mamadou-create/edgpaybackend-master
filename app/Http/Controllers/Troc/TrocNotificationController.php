<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TrocRequestStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;

class TrocNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $limit = max(1, min((int) $request->query('limit', 20), 100));
        $unreadOnly = filter_var($request->query('unread_only', true), FILTER_VALIDATE_BOOL);

        $query = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', TrocRequestStatusChanged::class)
            ->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $items = $query->limit($limit)->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn(DatabaseNotification $item) => $this->serializeNotification($item))->values(),
            'Notifications troc récupérées avec succès'
        );
    }

    public function markAsRead(string $notificationId): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $notification = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', TrocRequestStatusChanged::class)
            ->where('id', $notificationId)
            ->first();

        if (!$notification) {
            return ApiResponseClass::notFound('Notification troc introuvable.');
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
            $notification->refresh();
        }

        return ApiResponseClass::sendResponse(
            $this->serializeNotification($notification),
            'Notification troc marquée comme lue'
        );
    }

    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $updated = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', TrocRequestStatusChanged::class)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return ApiResponseClass::sendResponse(
            ['updated' => $updated],
            'Notifications troc marquées comme lues'
        );
    }

    private function serializeNotification(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => $notification->id,
            'request_id' => $data['request_id'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'title' => $data['title'] ?? 'Mise à jour troc',
            'body' => $data['body'] ?? 'Le statut de votre demande troc a changé.',
            'admin_notes' => $data['admin_notes'] ?? null,
            'source_label' => $data['source_label'] ?? null,
            'target_label' => $data['target_label'] ?? null,
            'processed_at' => $data['processed_at'] ?? null,
            'is_read' => $notification->read_at !== null,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
        ];
    }
}