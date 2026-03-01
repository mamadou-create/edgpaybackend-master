<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Interfaces\AnnouncementRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AnnouncementController extends Controller
{

    protected $announcementRepository;

    public function __construct(AnnouncementRepositoryInterface $announcementRepository)
    {
        $this->announcementRepository = $announcementRepository;
    }

    /**
     * Liste des annonces
     */

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $status = $request->get('status');

        // Récupérer les 20 dernières annonces
        $announcements = $this->announcementRepository->getLatestAnnouncements(
            role: $user->role->slug,
            userId: $user->id,
            status: $status,
            limit: 20
        );

        // Obtenir le nombre d'annonces non lues
        $unreadCount = $this->announcementRepository->getUnreadCount(
            role: $user->role->slug,
            userId: $user->id
        );

        // Formater la réponse
        $responseData = [
            'items' => AnnouncementResource::collection($announcements),
            'meta' => [
                'total' => $announcements->count(),
                'unread_count' => $unreadCount,
            ]
        ];

        return ApiResponseClass::sendResponse(
            $responseData,
            'Liste des annonces récupérée avec succès'
        );
    }


    /**
     * Créer une annonce
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['author_id'] = Auth::id();

        $announcement = $this->announcementRepository->create($data);

        // Éventuellement: diffuser l'annonce en temps réel
        // broadcast(new AnnouncementCreated($announcement))->toOthers();

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($announcement),
            'Annonce créée avec succès',
            201
        );
    }

    /**
     * Afficher une annonce
     */
    public function show(string $id): JsonResponse
    {
        $announcement = $this->announcementRepository->find($id);

        if (!$announcement) {
            return ApiResponseClass::sendError('Annonce non trouvée', 404);
        }

        $user = Auth::user();
        if (!$announcement->isForRole($user->role)) {
            return ApiResponseClass::sendError('Accès non autorisé à cette annonce', 403);
        }

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($announcement),
            'Annonce récupérée avec succès'
        );
    }

    /**
     * Marquer comme lu
     */
    public function markAsRead(string $id): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->announcementRepository->find($id);

        if (!$announcement) {
            return ApiResponseClass::sendError('Annonce non trouvée', 404);
        }

        if (!$announcement->isForRole($user->role->slug)) {
            return ApiResponseClass::sendError('Cette annonce ne vous est pas destinée', 403);
        }

        $this->announcementRepository->markAsRead($id, $user->id);

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($announcement->fresh()),
            'Annonce marquée comme lue'
        );
    }

    /**
     * Marquer toutes comme lues
     */
    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::user();
        $count = $this->announcementRepository->markAllAsRead($user->role->slug, $user->id);

        return ApiResponseClass::sendResponse(
            ['count' => $count],
            "{$count} annonces marquées comme lues"
        );
    }

    /**
     * Supprimer une annonce
     */
    public function destroy(string $id): JsonResponse
    {
        $user = Auth::user();

        if (!in_array($user->role, [RoleEnum::SUPER_ADMIN])) {
            return ApiResponseClass::sendError('Accès non autorisé', 403);
        }

        $announcement = $this->announcementRepository->find($id);

        if (!$announcement) {
            return ApiResponseClass::sendError('Annonce non trouvée', 404);
        }

        // Vérifier les permissions
        if ($user->role->slug !== RoleEnum::SUPER_ADMIN && $announcement->author_id !== $user->id) {
            return ApiResponseClass::sendError('Vous ne pouvez supprimer que vos propres annonces', 403);
        }

        $this->announcementRepository->delete($id);

        return ApiResponseClass::sendResponse(
            null,
            'Annonce supprimée avec succès'
        );
    }

    /**
     * Statistiques
     */
    public function stats(): JsonResponse
    {
        $user = Auth::user();
        $stats = $this->announcementRepository->getStats($user->role->slug, $user->id);

        $responseData = [
            'total' => $stats['total'],
            'unread' => $stats['unread'],
            'read' => $stats['read'],
            'recent' => AnnouncementResource::collection($stats['recent'])
        ];

        return ApiResponseClass::sendResponse($responseData, 'Statistiques récupérées avec succès');
    }
}
