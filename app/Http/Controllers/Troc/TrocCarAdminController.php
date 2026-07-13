<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\TrocCarPrice;
use App\Models\TrocCarRequest;
use App\Notifications\TrocCarRequestStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrocCarAdminController extends Controller
{
    private const DECOTES_KEY = 'troc_car_decotes';

    public function requestsIndex(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $status = trim((string) $request->query('status', ''));
        $queryText = trim((string) $request->query('q', ''));
        $limit = max(1, min((int) $request->query('limit', 80), 200));

        $query = TrocCarRequest::query()
            ->with(['user:id,display_name,email,phone'])
            ->orderByDesc('created_at');

        if ($status !== '' && in_array($status, TrocCarRequest::statuses(), true)) {
            $query->where('status', $status);
        }

        if ($queryText !== '') {
            $like = '%' . $queryText . '%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('source_brand', 'like', $like)
                    ->orWhere('source_model', 'like', $like)
                    ->orWhere('target_brand', 'like', $like)
                    ->orWhere('target_model', 'like', $like)
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
        foreach (TrocCarRequest::statuses() as $value) {
            $statusCounts[$value] = TrocCarRequest::query()->where('status', $value)->count();
        }

        return ApiResponseClass::sendResponse([
            'items' => $items->map(fn (TrocCarRequest $item) => $this->serializeRequest($item))->values(),
            'status_counts' => $statusCounts,
        ], 'Demandes troc voiture recuperees avec succes');
    }

    public function updateRequest(Request $request, TrocCarRequest $trocCarRequest): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $previousStatus = $trocCarRequest->status;

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(TrocCarRequest::statuses())],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trocCarRequest->status = (string) $validated['status'];
        $trocCarRequest->admin_notes = $validated['admin_notes'] ?? null;
        $trocCarRequest->processed_at = $trocCarRequest->status === TrocCarRequest::STATUS_PENDING
            ? null
            : now();
        $trocCarRequest->save();

        $trocCarRequest->loadMissing('user:id,display_name,email,phone');

        if (
            $previousStatus !== $trocCarRequest->status
            && in_array($trocCarRequest->status, [
                TrocCarRequest::STATUS_APPROVED,
                TrocCarRequest::STATUS_REJECTED,
                TrocCarRequest::STATUS_COMPLETED,
            ], true)
            && $trocCarRequest->user
        ) {
            $trocCarRequest->user->notify(new TrocCarRequestStatusChanged($trocCarRequest));
        }

        return ApiResponseClass::sendResponse(
            $this->serializeRequest($trocCarRequest),
            'Demande troc voiture mise a jour avec succes'
        );
    }

    public function catalogIndex(): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $items = TrocCarPrice::query()
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('year', 'desc')
            ->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn (TrocCarPrice $item) => $this->serializeCatalogItem($item))->values(),
            'Catalogue troc voiture recupere avec succes'
        );
    }

    public function storeCatalog(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $this->validateCatalogPayload($request);

        $item = TrocCarPrice::query()->create([
            'brand' => $validated['brand'],
            'model' => $validated['model'],
            'year' => (int) $validated['year'],
            'fuel' => isset($validated['fuel']) ? strtoupper((string) $validated['fuel']) : null,
            'transmission' => isset($validated['transmission']) ? strtoupper((string) $validated['transmission']) : null,
            'base_price' => $this->resolveBasePrice($validated),
        ]);

        return ApiResponseClass::created(
            $this->serializeCatalogItem($item),
            'Vehicule du catalogue troc cree avec succes'
        );
    }

    public function updateCatalog(Request $request, TrocCarPrice $catalogItem): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $this->validateCatalogPayload($request, $catalogItem->id);

        $catalogItem->update([
            'brand' => $validated['brand'],
            'model' => $validated['model'],
            'year' => (int) $validated['year'],
            'fuel' => isset($validated['fuel']) ? strtoupper((string) $validated['fuel']) : null,
            'transmission' => isset($validated['transmission']) ? strtoupper((string) $validated['transmission']) : null,
            'base_price' => $this->resolveBasePrice($validated),
        ]);

        return ApiResponseClass::sendResponse(
            $this->serializeCatalogItem($catalogItem->fresh()),
            'Vehicule du catalogue troc mis a jour avec succes'
        );
    }

    public function destroyCatalog(TrocCarPrice $catalogItem): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $catalogItem->delete();

        return ApiResponseClass::sendResponse(null, 'Vehicule du catalogue troc supprime avec succes');
    }

    public function decotes(): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        return ApiResponseClass::sendResponse(
            $this->resolveDecotes(),
            'Parametres de decote troc voiture recuperes avec succes'
        );
    }

    public function updateDecotes(Request $request): JsonResponse
    {
        if ($response = $this->ensureSuperAdmin()) {
            return $response;
        }

        $validated = $request->validate([
            'decotes' => ['required', 'array'],
            'decotes.*' => ['numeric', 'min:0', 'max:100'],
        ]);

        $decotes = array_merge($this->defaultDecotes(), $validated['decotes']);
        SystemSetting::query()->updateOrCreate(
            ['key' => self::DECOTES_KEY],
            [
                'value' => json_encode($decotes),
                'type' => 'json',
                'group' => 'troc',
                'description' => 'Pourcentages de decote pour les estimations Troc voiture.',
                'is_active' => true,
                'is_editable' => true,
                'order' => 0,
            ]
        );

        return ApiResponseClass::sendResponse(
            $decotes,
            'Parametres de decote troc voiture mis a jour avec succes'
        );
    }

    private function defaultDecotes(): array
    {
        return [
            'mileage_over_100000' => 8,
            'mileage_over_150000' => 15,
            'mileage_over_200000' => 25,
            'scratched' => 5,
            'broken' => 20,
            'engine_damaged' => 20,
            'gearbox_damaged' => 12,
            'body_cracked' => 10,
            'interior_worn' => 5,
            'air_conditioning_fault' => 5,
            'accident_history' => 15,
        ];
    }

    private function resolveDecotes(): array
    {
        $setting = SystemSetting::query()
            ->where('key', self::DECOTES_KEY)
            ->where('is_active', true)
            ->first();
        $stored = $setting?->formatted_value;

        return array_merge($this->defaultDecotes(), is_array($stored) ? $stored : []);
    }

    private function ensureSuperAdmin(): ?JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifie.');
        }

        if (!(bool) ($user->role?->is_super_admin ?? false)) {
            return ApiResponseClass::forbidden('Acces reserve au super admin.');
        }

        return null;
    }

    private function validateCatalogPayload(Request $request, ?string $ignoreId = null): array
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120'],
            'year' => ['required', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'fuel' => ['nullable', 'string', 'max:30'],
            'transmission' => ['nullable', 'string', 'max:30'],
            'market_price' => ['nullable', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (!array_key_exists('market_price', $validated) && !array_key_exists('base_price', $validated)) {
            throw ValidationException::withMessages([
                'market_price' => ['Le prix marche ou le prix de reference est requis.'],
            ]);
        }

        $duplicateQuery = TrocCarPrice::query()
            ->where('brand', $validated['brand'])
            ->where('model', $validated['model'])
            ->where('year', (int) $validated['year'])
            ->where('fuel', isset($validated['fuel']) ? strtoupper((string) $validated['fuel']) : null)
            ->where('transmission', isset($validated['transmission']) ? strtoupper((string) $validated['transmission']) : null);

        if ($ignoreId !== null) {
            $duplicateQuery->where('id', '!=', $ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'model' => ['Ce vehicule existe deja dans le catalogue.'],
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

    private function serializeCatalogItem(TrocCarPrice $item): array
    {
        return [
            'id' => $item->id,
            'brand' => $item->brand,
            'model' => $item->model,
            'year' => $item->year,
            'fuel' => $item->fuel,
            'transmission' => $item->transmission,
            'base_price' => (float) $item->base_price,
            'market_price' => $this->convertReferencePriceToGnf((float) $item->base_price),
            'currency' => config('troc.display_currency', 'GNF'),
            'created_at' => optional($item->created_at)?->toIso8601String(),
            'updated_at' => optional($item->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeRequest(TrocCarRequest $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status,
            'source_brand' => $item->source_brand,
            'source_model' => $item->source_model,
            'source_year' => $item->source_year,
            'source_fuel' => $item->source_fuel,
            'source_transmission' => $item->source_transmission,
            'mileage_km' => $item->mileage_km,
            'condition' => $item->condition,
            'condition_details' => $item->condition_details ?? [],
            'image_url' => $item->image_url,
            'image_analysis' => $item->image_analysis ?? [],
            'estimated_price' => (float) $item->estimated_price,
            'target_brand' => $item->target_brand,
            'target_model' => $item->target_model,
            'target_year' => $item->target_year,
            'target_fuel' => $item->target_fuel,
            'target_transmission' => $item->target_transmission,
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
