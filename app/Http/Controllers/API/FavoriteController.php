<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $type = trim((string) $request->query('type', ''));
        $items = Favorite::query()
            ->where('user_id', $user->id)
            ->when($type !== '', fn ($builder) => $builder->where('favoritable_type', $type))
            ->orderByDesc('created_at')
            ->get();

        return ApiResponseClass::sendResponse($items, 'Favoris récupérés avec succès');
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $validated = $request->validate([
            'favoritable_type' => ['required', 'string', 'max:120'],
            'favoritable_id' => ['required', 'uuid'],
        ]);

        $favorite = Favorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'favoritable_type' => $validated['favoritable_type'],
            'favoritable_id' => $validated['favoritable_id'],
        ]);

        return ApiResponseClass::created($favorite, 'Favori enregistré avec succès');
    }

    public function destroy(Favorite $favorite): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if ((string) $favorite->user_id !== (string) $user->id) {
            return ApiResponseClass::forbidden('Accès refusé à ce favori.');
        }

        $favorite->delete();

        return ApiResponseClass::sendResponse(null, 'Favori supprimé');
    }
}
