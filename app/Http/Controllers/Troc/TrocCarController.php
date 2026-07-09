<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\TrocCarPrice;
use App\Models\TrocCarRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TrocCarController extends Controller
{
    public function catalog(): JsonResponse
    {
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

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120'],
            'year' => ['required', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'fuel' => ['nullable', 'string', 'max:30'],
            'transmission' => ['nullable', 'string', 'max:30'],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:1500000'],
            'condition' => ['required', 'string', 'max:50'],
            'condition_details' => ['nullable', 'array'],
            'image_analysis' => ['nullable', 'array'],
        ]);

        $price = $this->findPrice(
            (string) $validated['brand'],
            (string) $validated['model'],
            (int) $validated['year'],
            isset($validated['fuel']) ? (string) $validated['fuel'] : null,
            isset($validated['transmission']) ? (string) $validated['transmission'] : null,
        );

        if ($price === null) {
            return ApiResponseClass::notFound('Prix de reference introuvable pour ce vehicule.');
        }

        $deductions = $this->computeDeductions(
            basePrice: (float) $price->base_price,
            mileageKm: (int) $validated['mileage_km'],
            condition: (string) $validated['condition'],
            conditionDetails: is_array($validated['condition_details'] ?? null) ? $validated['condition_details'] : [],
            imageAnalysis: is_array($validated['image_analysis'] ?? null) ? $validated['image_analysis'] : [],
        );

        $pricing = $this->applyPricingPolicy(
            basePrice: (float) $price->base_price,
            deductionTotal: (float) $deductions['total'],
        );

        return ApiResponseClass::sendResponse([
            'brand' => $price->brand,
            'model' => $price->model,
            'year' => $price->year,
            'fuel' => $price->fuel,
            'transmission' => $price->transmission,
            'base_price' => $this->convertReferencePriceToGnf((float) $price->base_price),
            'estimated_price' => $this->convertReferencePriceToGnf((float) $pricing['estimated_price']),
            'mileage_km' => (int) $validated['mileage_km'],
            'condition' => (string) $validated['condition'],
            'condition_details' => $deductions['condition_details'],
            'image_analysis' => $deductions['image_analysis'],
            'deductions' => $this->convertDeductionItemsToGnf($deductions['items']),
            'total_deduction' => $this->convertReferencePriceToGnf((float) $deductions['total']),
            'pricing_policy' => [
                'base_minus_deductions' => $this->convertReferencePriceToGnf((float) $pricing['base_minus_deductions']),
                'max_profitable_buyback' => $this->convertReferencePriceToGnf((float) $pricing['max_profitable_buyback']),
                'buyback_floor' => $this->convertReferencePriceToGnf((float) $pricing['buyback_floor']),
                'resale_price' => $this->convertReferencePriceToGnf((float) $pricing['resale_price']),
                'operational_cost' => $this->convertReferencePriceToGnf((float) $pricing['operational_cost']),
                'required_margin' => $this->convertReferencePriceToGnf((float) $pricing['required_margin']),
                'is_profitable' => (bool) $pricing['is_profitable'],
                'is_floor_limited' => (bool) $pricing['is_floor_limited'],
                'currency' => config('troc.display_currency', 'GNF'),
            ],
            'currency' => config('troc.display_currency', 'GNF'),
            'next_questions' => $deductions['next_questions'],
        ], 'Estimation troc voiture calculee avec succes');
    }

    public function trade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_price' => ['required', 'numeric', 'min:0'],
            'target_brand' => ['required', 'string', 'max:80'],
            'target_model' => ['required', 'string', 'max:120'],
            'target_year' => ['required', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'target_fuel' => ['nullable', 'string', 'max:30'],
            'target_transmission' => ['nullable', 'string', 'max:30'],
        ]);

        $targetPrice = $this->findPrice(
            (string) $validated['target_brand'],
            (string) $validated['target_model'],
            (int) $validated['target_year'],
            isset($validated['target_fuel']) ? (string) $validated['target_fuel'] : null,
            isset($validated['target_transmission']) ? (string) $validated['target_transmission'] : null,
        );

        if ($targetPrice === null) {
            return ApiResponseClass::notFound('Prix cible introuvable pour ce vehicule.');
        }

        $targetPriceGnf = $this->convertReferencePriceToGnf((float) $targetPrice->base_price);
        $userPriceGnf = round((float) $validated['user_price'], 0);
        $difference = round($targetPriceGnf - $userPriceGnf, 0);

        $message = $difference > 0
            ? 'Tu ajoutes ' . $this->formatGnf($difference)
            : ($difference < 0
                ? 'On te donne ' . $this->formatGnf(abs($difference))
                : 'Echange equilibre, aucun supplement.');

        return ApiResponseClass::sendResponse([
            'target_brand' => $targetPrice->brand,
            'target_model' => $targetPrice->model,
            'target_year' => $targetPrice->year,
            'target_fuel' => $targetPrice->fuel,
            'target_transmission' => $targetPrice->transmission,
            'target_price' => $targetPriceGnf,
            'user_price' => $userPriceGnf,
            'difference' => $difference,
            'message' => $message,
            'currency' => config('troc.display_currency', 'GNF'),
        ], 'Simulation troc voiture calculee avec succes');
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_brand' => ['required', 'string', 'max:80'],
            'source_model' => ['required', 'string', 'max:120'],
            'source_year' => ['required', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'source_fuel' => ['nullable', 'string', 'max:30'],
            'source_transmission' => ['nullable', 'string', 'max:30'],
            'mileage_km' => ['required', 'integer', 'min:0', 'max:1500000'],
            'condition' => ['required', 'string', 'max:50'],
            'condition_details' => ['nullable', 'array'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_analysis' => ['nullable', 'array'],
            'estimated_price' => ['required', 'numeric', 'min:0'],
            'target_brand' => ['required', 'string', 'max:80'],
            'target_model' => ['required', 'string', 'max:120'],
            'target_year' => ['required', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'target_fuel' => ['nullable', 'string', 'max:30'],
            'target_transmission' => ['nullable', 'string', 'max:30'],
            'target_price' => ['required', 'numeric', 'min:0'],
            'difference' => ['required', 'numeric'],
            'currency' => ['nullable', 'string', 'max:10'],
            'offer_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $trocRequest = TrocCarRequest::query()->create([
            'user_id' => Auth::id(),
            'source_brand' => $validated['source_brand'],
            'source_model' => $validated['source_model'],
            'source_year' => (int) $validated['source_year'],
            'source_fuel' => isset($validated['source_fuel']) ? strtoupper((string) $validated['source_fuel']) : null,
            'source_transmission' => isset($validated['source_transmission']) ? strtoupper((string) $validated['source_transmission']) : null,
            'mileage_km' => (int) $validated['mileage_km'],
            'condition' => $validated['condition'],
            'condition_details' => $validated['condition_details'] ?? [],
            'image_url' => $validated['image_url'] ?? null,
            'image_analysis' => $validated['image_analysis'] ?? [],
            'estimated_price' => (float) $validated['estimated_price'],
            'target_brand' => $validated['target_brand'],
            'target_model' => $validated['target_model'],
            'target_year' => (int) $validated['target_year'],
            'target_fuel' => isset($validated['target_fuel']) ? strtoupper((string) $validated['target_fuel']) : null,
            'target_transmission' => isset($validated['target_transmission']) ? strtoupper((string) $validated['target_transmission']) : null,
            'target_price' => (float) $validated['target_price'],
            'difference' => (float) $validated['difference'],
            'currency' => $validated['currency'] ?? config('troc.display_currency', 'GNF'),
            'offer_message' => $validated['offer_message'] ?? null,
            'status' => TrocCarRequest::STATUS_PENDING,
        ]);

        $trocRequest->loadMissing('user:id,display_name,email,phone');

        return ApiResponseClass::created([
            'request' => $this->serializeRequest($trocRequest),
        ], 'Demande de troc voiture envoyee avec succes');
    }

    public function myRequests(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifie.');
        }

        $status = trim((string) $request->query('status', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        $query = TrocCarRequest::query()
            ->where('user_id', $user->id)
            ->with(['user:id,display_name,email,phone'])
            ->orderByDesc('created_at');

        if ($status !== '' && in_array($status, TrocCarRequest::statuses(), true)) {
            $query->where('status', $status);
        }

        $items = $query->limit($limit)->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn (TrocCarRequest $item) => $this->serializeRequest($item))->values(),
            'Historique troc voiture recupere avec succes'
        );
    }

    private function convertDeductionItemsToGnf(array $items): array
    {
        return array_map(function (array $item): array {
            return [
                'label' => $item['label'] ?? '',
                'amount' => $this->convertReferencePriceToGnf((float) ($item['amount'] ?? 0)),
            ];
        }, $items);
    }

    private function convertReferencePriceToGnf(float $amount): float
    {
        $rate = max(1, (int) config('troc.reference_to_gnf_rate', 8700));

        return round($amount * $rate, 0);
    }

    private function formatGnf(float $amount): string
    {
        return number_format(round($amount, 0), 0, '.', ' ') . ' ' . config('troc.display_currency', 'GNF');
    }

    private function applyPricingPolicy(float $basePrice, float $deductionTotal): array
    {
        $rawOffer = max(0.0, round($basePrice - $deductionTotal, 2));

        $resaleFactor = max(0.1, (float) config('troc.pricing.resale_factor', 1.0));
        $operationalCostPercent = max(0.0, (float) config('troc.pricing.operational_cost_percent', 0.05));
        $minMarginPercent = max(0.0, (float) config('troc.pricing.min_margin_percent', 0.10));
        $minMarginFixed = max(0.0, (float) config('troc.pricing.min_margin_fixed', 8.0));
        $buybackFloorPercent = max(0.0, min(1.0, (float) config('troc.pricing.buyback_floor_percent', 0.35)));

        $resalePrice = round($basePrice * $resaleFactor, 2);
        $operationalCost = round($resalePrice * $operationalCostPercent, 2);
        $requiredMargin = round(max($resalePrice * $minMarginPercent, $minMarginFixed), 2);

        $maxProfitableBuyback = max(0.0, round($resalePrice - $deductionTotal - $operationalCost - $requiredMargin, 2));
        $policyOffer = min($rawOffer, $maxProfitableBuyback);

        $buybackFloor = round($basePrice * $buybackFloorPercent, 2);
        $estimated = max(0.0, round(max($policyOffer, min($buybackFloor, $maxProfitableBuyback)), 2));

        return [
            'estimated_price' => $estimated,
            'base_minus_deductions' => $rawOffer,
            'max_profitable_buyback' => $maxProfitableBuyback,
            'buyback_floor' => $buybackFloor,
            'resale_price' => $resalePrice,
            'operational_cost' => $operationalCost,
            'required_margin' => $requiredMargin,
            'is_profitable' => $maxProfitableBuyback > 0,
            'is_floor_limited' => $estimated > $policyOffer,
        ];
    }

    private function computeDeductions(
        float $basePrice,
        int $mileageKm,
        string $condition,
        array $conditionDetails = [],
        array $imageAnalysis = [],
    ): array {
        $normalizedCondition = $this->normalize($condition);
        $normalizedDetails = $this->normalizeConditionDetails($conditionDetails, $imageAnalysis);
        $items = [];
        $total = 0.0;
        $nextQuestions = [];

        // Helper : décote en % du prix de référence (proportionnel à tous les véhicules)
        $pct = fn (float $percent): float => round($basePrice * $percent / 100, 2);

        // ——— Kilométrage ————————————————————————————————————————————
        if ($mileageKm > 200_000) {
            $items[] = ['label' => 'Kilométrage > 200 000 km (-25%)', 'amount' => $pct(25)];
            $total   += $pct(25);
        } elseif ($mileageKm > 150_000) {
            $items[] = ['label' => 'Kilométrage > 150 000 km (-15%)', 'amount' => $pct(15)];
            $total   += $pct(15);
        } elseif ($mileageKm > 100_000) {
            $items[] = ['label' => 'Kilométrage > 100 000 km (-8%)', 'amount' => $pct(8)];
            $total   += $pct(8);
        }

        // ——— État général ————————————————————————————————————————————
        if (in_array($normalizedCondition, ['scratched', 'raye'], true)) {
            $items[] = ['label' => 'Carrosserie rayée (-5%)', 'amount' => $pct(5)];
            $total   += $pct(5);
        }

        if (in_array($normalizedCondition, ['broken', 'casse', 'cassee'], true)) {
            $items[] = ['label' => 'État général dégradé (-20%)', 'amount' => $pct(20)];
            $total   += $pct(20);
        }

        // ——— Moteur ——————————————————————————————————————————————————
        if (($normalizedDetails['engine_condition'] ?? 'good') === 'damaged') {
            $items[] = ['label' => 'Moteur à vérifier (-20%)', 'amount' => $pct(20)];
            $total   += $pct(20);
        }

        // ——— Boîte de vitesse ———————————————————————————————————————
        if (($normalizedDetails['gearbox_condition'] ?? 'good') === 'damaged') {
            $items[] = ['label' => 'Boîte de vitesse fragile (-12%)', 'amount' => $pct(12)];
            $total   += $pct(12);
        }

        // ——— Carrosserie ————————————————————————————————————————————
        if (($normalizedDetails['body_condition'] ?? 'good') === 'cracked') {
            $items[] = ['label' => 'Carrosserie endommagée (-10%)', 'amount' => $pct(10)];
            $total   += $pct(10);
        }

        // ——— Intérieur ——————————————————————————————————————————————
        if (($normalizedDetails['interior_condition'] ?? 'good') === 'worn') {
            $items[] = ['label' => 'Intérieur usé (-5%)', 'amount' => $pct(5)];
            $total   += $pct(5);
        }

        // ——— Climatisation —————————————————————————————————————————
        if (($normalizedDetails['air_conditioning_ok'] ?? true) === false) {
            $items[] = ['label' => 'Climatisation non fonctionnelle (-5%)', 'amount' => $pct(5)];
            $total   += $pct(5);
        }

        // ——— Historique accident ———————————————————————————————————
        if (($normalizedDetails['accident_history'] ?? false) === true) {
            $items[] = ['label' => 'Historique accident (-15%)', 'amount' => $pct(15)];
            $total   += $pct(15);
        }

        // ——— Questions complémentaires ————————————————————————————
        if (($normalizedDetails['engine_condition'] ?? 'unknown') === 'unknown') {
            $nextQuestions[] = 'Le moteur a-t-il des fuites ou un bruit anormal ?';
        }

        if (($normalizedDetails['gearbox_condition'] ?? 'unknown') === 'unknown') {
            $nextQuestions[] = 'La boîte de vitesse passe-t-elle bien tous les rapports ?';
        }

        if (($normalizedDetails['accident_history'] ?? null) === null) {
            $nextQuestions[] = 'Le véhicule a-t-il déjà subi un accident important ?';
        }

        return [
            'items'             => $items,
            'total'             => $total,
            'condition_details' => $normalizedDetails,
            'image_analysis'    => $imageAnalysis,
            'next_questions'    => array_values(array_unique($nextQuestions)),
        ];
    }

    private function normalizeConditionDetails(array $conditionDetails, array $imageAnalysis = []): array
    {
        $issues = is_array($imageAnalysis['detected_issues'] ?? null)
            ? $imageAnalysis['detected_issues']
            : [];

        $engineCondition = $this->normalizeDetailValue((string) ($conditionDetails['engine_condition'] ?? 'unknown'));
        $gearboxCondition = $this->normalizeDetailValue((string) ($conditionDetails['gearbox_condition'] ?? 'unknown'));
        $bodyCondition = $this->normalizeDetailValue((string) ($conditionDetails['body_condition'] ?? 'good'));
        $interiorCondition = $this->normalizeInteriorValue((string) ($conditionDetails['interior_condition'] ?? 'good'));

        if (($issues['engine_issue'] ?? false) === true) {
            $engineCondition = 'damaged';
        }

        if (($issues['gearbox_issue'] ?? false) === true) {
            $gearboxCondition = 'damaged';
        }

        if (($issues['body_damage'] ?? false) === true) {
            $bodyCondition = 'cracked';
        }

        if (($issues['interior_damage'] ?? false) === true) {
            $interiorCondition = 'worn';
        }

        return [
            'engine_condition' => $engineCondition,
            'gearbox_condition' => $gearboxCondition,
            'body_condition' => $bodyCondition,
            'interior_condition' => $interiorCondition,
            'air_conditioning_ok' => array_key_exists('air_conditioning_ok', $conditionDetails)
                ? filter_var($conditionDetails['air_conditioning_ok'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null,
            'accident_history' => array_key_exists('accident_history', $conditionDetails)
                ? filter_var($conditionDetails['accident_history'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null,
            'service_up_to_date' => (bool) ($conditionDetails['service_up_to_date'] ?? false),
        ];
    }

    private function normalizeDetailValue(string $value): string
    {
        return match ($this->normalize($value)) {
            'excellent', 'good', 'bon', 'propre', 'clean' => 'good',
            'scratched', 'raye', 'raye' => 'scratched',
            'broken', 'cracked', 'casse', 'cassee', 'damaged', 'hs', 'defectueux' => 'damaged',
            '', 'unknown', 'inconnu' => 'unknown',
            default => 'unknown',
        };
    }

    private function normalizeInteriorValue(string $value): string
    {
        return match ($this->normalize($value)) {
            'excellent', 'good', 'bon', 'propre', 'clean' => 'good',
            'worn', 'use', 'usure' => 'worn',
            '', 'unknown', 'inconnu' => 'unknown',
            default => 'unknown',
        };
    }

    private function findPrice(
        string $brand,
        string $model,
        int $year,
        ?string $fuel,
        ?string $transmission,
    ): ?TrocCarPrice {
        $normalizedBrand = $this->normalize($brand);
        $normalizedModel = $this->normalize($model);
        $normalizedFuel = $fuel !== null ? $this->normalize($fuel) : null;
        $normalizedTransmission = $transmission !== null ? $this->normalize($transmission) : null;

        $query = TrocCarPrice::query()
            ->whereRaw("LOWER(REPLACE(brand, ' ', '')) = ?", [$normalizedBrand])
            ->whereRaw("LOWER(REPLACE(model, ' ', '')) = ?", [$normalizedModel])
            ->where('year', $year);

        if ($normalizedFuel !== null && $normalizedFuel !== '') {
            $query->whereRaw("LOWER(REPLACE(COALESCE(fuel, ''), ' ', '')) = ?", [$normalizedFuel]);
        }

        if ($normalizedTransmission !== null && $normalizedTransmission !== '') {
            $query->whereRaw("LOWER(REPLACE(COALESCE(transmission, ''), ' ', '')) = ?", [$normalizedTransmission]);
        }

        $exactMatch = $query->first();
        if ($exactMatch !== null) {
            return $exactMatch;
        }

        $fallback = TrocCarPrice::query()
            ->whereRaw("LOWER(REPLACE(brand, ' ', '')) = ?", [$normalizedBrand])
            ->whereRaw("LOWER(REPLACE(model, ' ', '')) = ?", [$normalizedModel])
            ->orderByRaw('ABS(year - ?) asc', [$year])
            ->first();

        if ($fallback !== null) {
            Log::info('troc.car.price_search.fallback', [
                'brand' => $brand,
                'model' => $model,
                'requested_year' => $year,
                'matched_year' => $fallback->year,
            ]);
            return $fallback;
        }

        return null;
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
            'user' => $item->user ? [
                'id' => $item->user->id,
                'display_name' => $item->user->display_name,
                'email' => $item->user->email,
                'phone' => $item->user->phone,
            ] : null,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'î', 'ï', 'ô', 'ö', 'ù', 'û', 'ç'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'i', 'i', 'o', 'o', 'u', 'u', 'c'],
            $value,
        );

        return preg_replace('/\s+/', '', $value) ?? $value;
    }
}
