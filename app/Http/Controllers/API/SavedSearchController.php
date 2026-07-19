<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\SavedSearchRadarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SavedSearchController extends Controller
{
    public function __construct(private readonly SavedSearchRadarService $radarService)
    {
    }

    public function index(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $items = SavedSearch::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        return ApiResponseClass::sendResponse($items, 'Recherches enregistrées récupérées avec succès');
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $saved = SavedSearch::query()->create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'filters' => $validated['filters'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return ApiResponseClass::created($saved, 'Recherche enregistrée avec succès');
    }

    public function update(Request $request, SavedSearch $savedSearch): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if ((string) $savedSearch->user_id !== (string) $user->id) {
            return ApiResponseClass::forbidden('Accès refusé à cette recherche enregistrée.');
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'filters' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'last_notified_at' => ['nullable', 'date'],
        ]);

        $savedSearch->fill($validated);
        $savedSearch->save();

        return ApiResponseClass::sendResponse($savedSearch, 'Recherche enregistrée mise à jour');
    }

    public function destroy(SavedSearch $savedSearch): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if ((string) $savedSearch->user_id !== (string) $user->id) {
            return ApiResponseClass::forbidden('Accès refusé à cette recherche enregistrée.');
        }

        $savedSearch->delete();

        return ApiResponseClass::sendResponse(null, 'Recherche enregistrée supprimée');
    }

    public function scan(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $validated = $request->validate([
            'notify' => ['nullable', 'boolean'],
        ]);

        $results = $this->radarService->scanForUser(
            user: $user,
            notify: (bool) ($validated['notify'] ?? true),
        );

        return ApiResponseClass::sendResponse(
            ['results' => $results],
            'Scan des recherches enregistrées exécuté avec succès'
        );
    }
}
