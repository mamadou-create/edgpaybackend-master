<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\TrocPhonePrice;
use App\Models\TrocRequest;
use App\Notifications\TrocRequestStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrocAdminController extends Controller
{
    private const DECOTES_KEY = 'troc_phone_decotes';

    public function requestsIndex(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $status = trim((string) $request->query('status', ''));
        $queryText = trim((string) $request->query('q', ''));
        $limit = max(1, min((int) $request->query('limit', 80), 200));

        $query = TrocRequest::query()
            ->with(['user:id,display_name,email,phone'])
            ->orderByDesc('created_at');

        if ($status !== '' && in_array($status, TrocRequest::statuses(), true)) {
            $query->where('status', $status);
        }

        if ($queryText !== '') {
            $like = '%' . $queryText . '%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('source_model', 'like', $like)
                    ->orWhere('target_model', 'like', $like)
                    ->orWhere('source_storage', 'like', $like)
                    ->orWhere('target_storage', 'like', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery
                            ->where('display_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            });
        }

        $items = $query->limit($limit)->get();
        $statusCounts = [];
        foreach (TrocRequest::statuses() as $value) {
            $statusCounts[$value] = TrocRequest::query()->where('status', $value)->count();
        }

        return ApiResponseClass::sendResponse([
            'items' => $items->map(fn(TrocRequest $item) => $this->serializeRequest($item))->values(),
            'status_counts' => $statusCounts,
        ], 'Demandes troc récupérées avec succès');
    }

    public function updateRequest(Request $request, TrocRequest $trocRequest): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $previousStatus = $trocRequest->status;

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(TrocRequest::statuses())],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trocRequest->status = (string) $validated['status'];
        $trocRequest->admin_notes = $validated['admin_notes'] ?? null;
        $trocRequest->processed_at = $trocRequest->status === TrocRequest::STATUS_PENDING
            ? null
            : now();
        $trocRequest->save();

        $trocRequest->loadMissing('user:id,display_name,email,phone');

        if (
            $previousStatus !== $trocRequest->status
            && in_array($trocRequest->status, [
                TrocRequest::STATUS_APPROVED,
                TrocRequest::STATUS_REJECTED,
                TrocRequest::STATUS_COMPLETED,
            ], true)
            && $trocRequest->user
        ) {
            $trocRequest->user->notify(new TrocRequestStatusChanged($trocRequest));
        }

        return ApiResponseClass::sendResponse(
            $this->serializeRequest($trocRequest),
            'Demande troc mise à jour avec succès'
        );
    }

    public function catalogIndex(): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $items = TrocPhonePrice::query()
            ->orderBy('model')
            ->orderBy('storage')
            ->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn(TrocPhonePrice $item) => $this->serializeCatalogItem($item))->values(),
            'Catalogue troc récupéré avec succès'
        );
    }

    public function storeCatalog(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $this->validateCatalogPayload($request);

        $item = TrocPhonePrice::query()->create([
            'model' => $validated['model'],
            'storage' => strtoupper($validated['storage']),
            'base_price' => $this->resolveBasePrice($validated),
        ]);

        return ApiResponseClass::created(
            $this->serializeCatalogItem($item),
            'Produit du catalogue troc créé avec succès'
        );
    }

    public function updateCatalog(Request $request, TrocPhonePrice $catalogItem): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $this->validateCatalogPayload($request, $catalogItem->id);

        $catalogItem->update([
            'model' => $validated['model'],
            'storage' => strtoupper($validated['storage']),
            'base_price' => $this->resolveBasePrice($validated),
        ]);

        return ApiResponseClass::sendResponse(
            $this->serializeCatalogItem($catalogItem->fresh()),
            'Produit du catalogue troc mis à jour avec succès'
        );
    }

    public function destroyCatalog(TrocPhonePrice $catalogItem): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $catalogItem->delete();

        return ApiResponseClass::sendResponse(null, 'Produit du catalogue troc supprimé avec succès');
    }

    public function decotes(): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        return ApiResponseClass::sendResponse(
            $this->convertDecotesToGnf($this->resolveDecotes()),
            'Parametres de decote telephone recuperes avec succes'
        );
    }

    public function updateDecotes(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $request->validate([
            'decotes' => ['required', 'array'],
            'decotes.*' => ['numeric', 'min:0', 'max:1000000000'],
        ]);
        $stored = [];
        foreach ($validated['decotes'] as $key => $amount) {
            $stored[$key] = $this->convertGnfToReference((float) $amount);
        }
        $decotes = array_merge($this->defaultDecotes(), $stored);

        SystemSetting::query()->updateOrCreate(
            ['key' => self::DECOTES_KEY],
            [
                'value' => json_encode($decotes),
                'type' => 'json',
                'group' => 'troc',
                'description' => 'Montants de decote pour les estimations Troc telephone.',
                'is_active' => true,
                'is_editable' => true,
                'order' => 0,
            ]
        );

        return ApiResponseClass::sendResponse(
            $this->convertDecotesToGnf($decotes),
            'Parametres de decote telephone mis a jour avec succes'
        );
    }

    private function defaultDecotes(): array
    {
        return [
            'battery_under_80' => 40, 'battery_under_90' => 20,
            'scratched' => 15, 'broken' => 50, 'screen_scratched' => 12,
            'screen_cracked' => 45, 'back_scratched' => 10, 'back_cracked' => 25,
            'frame_dented' => 15, 'camera_damaged' => 20, 'biometric_fault' => 20, 'repaired' => 15,
        ];
    }

    private function resolveDecotes(): array
    {
        $setting = SystemSetting::query()->where('key', self::DECOTES_KEY)->where('is_active', true)->first();
        $stored = $setting?->formatted_value;
        return array_merge($this->defaultDecotes(), is_array($stored) ? $stored : []);
    }

    private function convertDecotesToGnf(array $decotes): array
    {
        return array_map(fn ($amount) => $this->convertReferencePriceToGnf((float) $amount), $decotes);
    }

    private function ensureSuperAdmin(): ?JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if (!(bool) ($user->role?->is_super_admin ?? false)) {
            return ApiResponseClass::forbidden('Accès réservé au super admin.');
        }

        return null;
    }

    private function validateCatalogPayload(Request $request, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:120'],
            'storage' => ['required', 'string', 'max:50'],
            'market_price' => ['nullable', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (!array_key_exists('market_price', $validated) && !array_key_exists('base_price', $validated)) {
            throw ValidationException::withMessages([
                'market_price' => ['Le prix marché ou le prix de référence est requis.'],
            ]);
        }

        $duplicateQuery = TrocPhonePrice::query()
            ->where('model', $validated['model'])
            ->where('storage', strtoupper((string) $validated['storage']));

        if ($ignoreId !== null) {
            $duplicateQuery->where('id', '!=', $ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'model' => ['Ce modèle avec cette capacité existe déjà dans le catalogue.'],
            ]);
        }

        return $validated;
    }

    private function resolveBasePrice(array $validated): float
    {
        if (isset($validated['market_price'])) {
            return $this->convertGnfToReference((float) $validated['market_price']);
        }

        return round((float) $validated['base_price'], 2);
    }

    private function convertGnfToReference(float $amount): float
    {
        $rate = max(1, (int) config('troc.reference_to_gnf_rate', 8700));

        return round($amount / $rate, 2);
    }

    private function convertReferencePriceToGnf(float $amount): float
    {
        $rate = max(1, (int) config('troc.reference_to_gnf_rate', 8700));

        return round($amount * $rate, 0);
    }

    private function serializeCatalogItem(TrocPhonePrice $item): array
    {
        return [
            'id' => $item->id,
            'model' => $item->model,
            'storage' => $item->storage,
            'base_price' => (float) $item->base_price,
            'market_price' => $this->convertReferencePriceToGnf((float) $item->base_price),
            'currency' => config('troc.display_currency', 'GNF'),
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeRequest(TrocRequest $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status,
            'source_model' => $item->source_model,
            'source_storage' => $item->source_storage,
            'battery' => $item->battery,
            'condition' => $item->condition,
            'condition_details' => $item->condition_details ?? [],
            'image_url' => $item->image_url,
            'image_analysis' => $item->image_analysis ?? [],
            'estimated_price' => (float) $item->estimated_price,
            'target_model' => $item->target_model,
            'target_storage' => $item->target_storage,
            'target_price' => (float) $item->target_price,
            'difference' => (float) $item->difference,
            'currency' => $item->currency,
            'offer_message' => $item->offer_message,
            'admin_notes' => $item->admin_notes,
            'processed_at' => optional($item->processed_at)?->toIso8601String(),
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
            'user' => $item->user ? [
                'id' => $item->user->id,
                'display_name' => $item->user->display_name,
                'email' => $item->user->email,
                'phone' => $item->user->phone,
            ] : null,
        ];
    }
}