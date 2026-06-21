<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\TrocPhonePrice;
use App\Models\TrocRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TrocController extends Controller
{
    public function catalog(): JsonResponse
    {
        $items = TrocPhonePrice::query()
            ->orderBy('model')
            ->orderBy('storage')
            ->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn(TrocPhonePrice $item) => $this->serializeCatalogItem($item))->values(),
            'Catalogue troc récupéré avec succès'
        );
    }

    public function evaluate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'string', 'max:120'],
            'storage' => ['required', 'string', 'max:50'],
            'battery' => ['required', 'integer', 'min:0', 'max:100'],
            'condition' => ['required', 'string', 'max:50'],
            'condition_details' => ['nullable', 'array'],
            'image_analysis' => ['nullable', 'array'],
        ]);

        $price = $this->findPrice(
            (string) $validated['model'],
            (string) $validated['storage'],
        );

        if ($price === null) {
            return ApiResponseClass::notFound('Prix de référence introuvable pour ce téléphone.');
        }

        $deductions = $this->computeDeductions(
            battery: (int) $validated['battery'],
            condition: (string) $validated['condition'],
            conditionDetails: is_array($validated['condition_details'] ?? null) ? $validated['condition_details'] : [],
            imageAnalysis: is_array($validated['image_analysis'] ?? null) ? $validated['image_analysis'] : [],
        );

        $pricing = $this->applyPricingPolicy(
            basePrice: (float) $price->base_price,
            deductionTotal: (float) $deductions['total'],
        );

        $estimatedPrice = $pricing['estimated_price'];
        $convertedBasePrice = $this->convertReferencePriceToGnf((float) $price->base_price);
        $convertedEstimatedPrice = $this->convertReferencePriceToGnf($estimatedPrice);
        $convertedTotalDeduction = $this->convertReferencePriceToGnf($deductions['total']);

        return ApiResponseClass::sendResponse([
            'model' => $price->model,
            'storage' => $price->storage,
            'base_price' => $convertedBasePrice,
            'estimated_price' => $convertedEstimatedPrice,
            'battery' => (int) $validated['battery'],
            'condition' => (string) $validated['condition'],
            'condition_details' => $deductions['condition_details'],
            'image_analysis' => $deductions['image_analysis'],
            'deductions' => $this->convertDeductionItemsToGnf($deductions['items']),
            'total_deduction' => $convertedTotalDeduction,
            'pricing_policy' => [
                'base_minus_deductions' => $this->convertReferencePriceToGnf($pricing['base_minus_deductions']),
                'max_profitable_buyback' => $this->convertReferencePriceToGnf($pricing['max_profitable_buyback']),
                'buyback_floor' => $this->convertReferencePriceToGnf($pricing['buyback_floor']),
                'resale_price' => $this->convertReferencePriceToGnf($pricing['resale_price']),
                'operational_cost' => $this->convertReferencePriceToGnf($pricing['operational_cost']),
                'required_margin' => $this->convertReferencePriceToGnf($pricing['required_margin']),
                'is_profitable' => $pricing['is_profitable'],
                'is_floor_limited' => $pricing['is_floor_limited'],
                'currency' => config('troc.display_currency', 'GNF'),
            ],
            'currency' => config('troc.display_currency', 'GNF'),
            'next_questions' => $deductions['next_questions'],
        ], 'Estimation Troc calculée avec succès');
    }

    public function trade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_price' => ['required', 'numeric', 'min:0'],
            'target_model' => ['required', 'string', 'max:120'],
            'target_storage' => ['nullable', 'string', 'max:50'],
        ]);

        [$targetModel, $targetStorage] = $this->extractTarget(
            (string) $validated['target_model'],
            isset($validated['target_storage']) ? (string) $validated['target_storage'] : null,
        );

        $targetPrice = $this->findPrice($targetModel, $targetStorage);

        if ($targetPrice === null) {
            return ApiResponseClass::notFound('Prix cible introuvable pour ce modèle.');
        }

        $targetPriceGnf = $this->convertReferencePriceToGnf((float) $targetPrice->base_price);
        $userPriceGnf = round((float) $validated['user_price'], 0);
        $difference = round($targetPriceGnf - $userPriceGnf, 0);

        $message = $difference > 0
            ? 'Tu ajoutes ' . $this->formatGnf($difference)
            : ($difference < 0
                ? 'On te donne ' . $this->formatGnf(abs($difference))
                : 'Échange équilibré, aucun supplément.');

        return ApiResponseClass::sendResponse([
            'target_model' => $targetPrice->model,
            'target_storage' => $targetPrice->storage,
            'target_price' => $targetPriceGnf,
            'user_price' => $userPriceGnf,
            'difference' => $difference,
            'message' => $message,
            'currency' => config('troc.display_currency', 'GNF'),
        ], 'Simulation de troc calculée avec succès');
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_model' => ['required', 'string', 'max:120'],
            'source_storage' => ['required', 'string', 'max:50'],
            'battery' => ['required', 'integer', 'min:0', 'max:100'],
            'condition' => ['required', 'string', 'max:50'],
            'condition_details' => ['nullable', 'array'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_analysis' => ['nullable', 'array'],
            'estimated_price' => ['required', 'numeric', 'min:0'],
            'target_model' => ['required', 'string', 'max:120'],
            'target_storage' => ['required', 'string', 'max:50'],
            'target_price' => ['required', 'numeric', 'min:0'],
            'difference' => ['required', 'numeric'],
            'currency' => ['nullable', 'string', 'max:10'],
            'offer_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $trocRequest = TrocRequest::query()->create([
            'user_id' => Auth::id(),
            'source_model' => $validated['source_model'],
            'source_storage' => strtoupper((string) $validated['source_storage']),
            'battery' => (int) $validated['battery'],
            'condition' => $validated['condition'],
            'condition_details' => $validated['condition_details'] ?? [],
            'image_url' => $validated['image_url'] ?? null,
            'image_analysis' => $validated['image_analysis'] ?? [],
            'estimated_price' => (float) $validated['estimated_price'],
            'target_model' => $validated['target_model'],
            'target_storage' => strtoupper((string) $validated['target_storage']),
            'target_price' => (float) $validated['target_price'],
            'difference' => (float) $validated['difference'],
            'currency' => $validated['currency'] ?? config('troc.display_currency', 'GNF'),
            'offer_message' => $validated['offer_message'] ?? null,
            'status' => TrocRequest::STATUS_PENDING,
        ]);

        $trocRequest->loadMissing('user:id,display_name,email,phone');

        return ApiResponseClass::created([
            'request' => $this->serializeRequest($trocRequest),
        ], 'Demande de troc envoyée avec succès');
    }

    public function myRequests(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $status = trim((string) $request->query('status', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        $query = TrocRequest::query()
            ->where('user_id', $user->id)
            ->with(['user:id,display_name,email,phone'])
            ->orderByDesc('created_at');

        if ($status !== '' && in_array($status, TrocRequest::statuses(), true)) {
            $query->where('status', $status);
        }

        $items = $query->limit($limit)->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn(TrocRequest $item) => $this->serializeRequest($item))->values(),
            'Historique troc récupéré avec succès'
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

        // Offre maximale qui reste rentable apres reconditionnement + frais + marge minimale.
        $maxProfitableBuyback = max(0.0, round($resalePrice - $deductionTotal - $operationalCost - $requiredMargin, 2));
        $policyOffer = min($rawOffer, $maxProfitableBuyback);

        // Plancher commercial: si la reprise est trop basse, on evite de descendre sous ce seuil.
        // La valeur finale reste bornee par la rentabilite pour ne pas vendre a perte.
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
        int $battery,
        string $condition,
        array $conditionDetails = [],
        array $imageAnalysis = [],
    ): array
    {
        $normalizedCondition = $this->normalize($condition);
        $normalizedDetails = $this->normalizeConditionDetails($conditionDetails, $imageAnalysis);
        $items = [];
        $total = 0.0;
        $nextQuestions = [];

        if ($battery < 80) {
            $items[] = ['label' => 'Batterie < 80%', 'amount' => 40.0];
            $total += 40.0;
        } elseif ($battery < 90) {
            $items[] = ['label' => 'Batterie < 90%', 'amount' => 20.0];
            $total += 20.0;
        }

        if (in_array($normalizedCondition, ['scratched', 'raye', 'raye'], true)) {
            $items[] = ['label' => 'État rayé', 'amount' => 15.0];
            $total += 15.0;
        }

        if (in_array($normalizedCondition, ['broken', 'casse', 'cassé'], true)) {
            $items[] = ['label' => 'État cassé', 'amount' => 50.0];
            $total += 50.0;
        }

        if (($normalizedDetails['screen_condition'] ?? 'good') === 'scratched') {
            $items[] = ['label' => 'Micro-rayures écran', 'amount' => 12.0];
            $total += 12.0;
        }

        if (($normalizedDetails['screen_condition'] ?? 'good') === 'cracked') {
            $items[] = ['label' => 'Écran fissuré', 'amount' => 45.0];
            $total += 45.0;
        }

        if (($normalizedDetails['back_condition'] ?? 'good') === 'scratched') {
            $items[] = ['label' => 'Dos rayé', 'amount' => 10.0];
            $total += 10.0;
        }

        if (($normalizedDetails['back_condition'] ?? 'good') === 'cracked') {
            $items[] = ['label' => 'Dos cassé', 'amount' => 25.0];
            $total += 25.0;
        }

        if (($normalizedDetails['frame_condition'] ?? 'good') === 'dented') {
            $items[] = ['label' => 'Châssis enfoncé', 'amount' => 15.0];
            $total += 15.0;
        }

        if (($normalizedDetails['camera_condition'] ?? 'good') === 'damaged') {
            $items[] = ['label' => 'Caméra endommagée', 'amount' => 20.0];
            $total += 20.0;
        }

        if (($normalizedDetails['face_id_works'] ?? true) === false) {
            $items[] = ['label' => 'Face ID / Touch ID indisponible', 'amount' => 20.0];
            $total += 20.0;
        }

        if (($normalizedDetails['repaired'] ?? false) === true) {
            $items[] = ['label' => 'Téléphone déjà réparé', 'amount' => 15.0];
            $total += 15.0;
        }

        if (($normalizedDetails['camera_condition'] ?? 'good') === 'unknown') {
            $nextQuestions[] = 'La caméra arrière est-elle nette et sans tache ?';
        }

        if (($normalizedDetails['face_id_works'] ?? null) === null) {
            $nextQuestions[] = 'Face ID ou Touch ID fonctionne-t-il correctement ?';
        }

        if (($normalizedDetails['screen_condition'] ?? 'good') === 'unknown') {
            $nextQuestions[] = 'L’écran a-t-il des rayures profondes ou des fissures visibles ?';
        }

        return [
            'items' => $items,
            'total' => $total,
            'condition_details' => $normalizedDetails,
            'image_analysis' => $imageAnalysis,
            'next_questions' => array_values(array_unique($nextQuestions)),
        ];
    }

    private function normalizeConditionDetails(array $conditionDetails, array $imageAnalysis = []): array
    {
        $issues = is_array($imageAnalysis['detected_issues'] ?? null)
            ? $imageAnalysis['detected_issues']
            : [];

        $screenCondition = $this->normalizeDetailValue((string) ($conditionDetails['screen_condition'] ?? 'good'));
        $backCondition = $this->normalizeDetailValue((string) ($conditionDetails['back_condition'] ?? 'good'));
        $frameCondition = $this->normalizeDetailValue((string) ($conditionDetails['frame_condition'] ?? 'good'));
        $cameraCondition = $this->normalizeDetailValue((string) ($conditionDetails['camera_condition'] ?? 'unknown'));

        if (($issues['screen_cracks'] ?? false) === true) {
            $screenCondition = 'cracked';
        } elseif (($issues['screen_scratches'] ?? false) === true && $screenCondition !== 'cracked') {
            $screenCondition = 'scratched';
        }

        if (($issues['back_glass_damage'] ?? false) === true && $backCondition !== 'cracked') {
            $backCondition = 'cracked';
        }

        if (($issues['frame_dents'] ?? false) === true) {
            $frameCondition = 'dented';
        }

        if (($issues['camera_damage'] ?? false) === true) {
            $cameraCondition = 'damaged';
        }

        return [
            'screen_condition' => $screenCondition,
            'back_condition' => $backCondition,
            'frame_condition' => $frameCondition,
            'camera_condition' => $cameraCondition,
            'face_id_works' => array_key_exists('face_id_works', $conditionDetails)
                ? filter_var($conditionDetails['face_id_works'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                : null,
            'repaired' => (bool) ($conditionDetails['repaired'] ?? false),
            'charger_included' => (bool) ($conditionDetails['charger_included'] ?? false),
            'box_included' => (bool) ($conditionDetails['box_included'] ?? false),
        ];
    }

    private function normalizeDetailValue(string $value): string
    {
        return match ($this->normalize($value)) {
            'excellent', 'good', 'bon', 'propre', 'clean', '' => 'good',
            'scratched', 'raye', 'raye' => 'scratched',
            'broken', 'cracked', 'casse', 'cassee', 'cassee' => 'cracked',
            'dented', 'bosseler', 'bossele', 'enfonce' => 'dented',
            'damaged', 'hs', 'defectueux' => 'damaged',
            default => 'unknown',
        };
    }

    private function extractTarget(string $targetModel, ?string $targetStorage): array
    {
        if ($targetStorage !== null && trim($targetStorage) !== '') {
            return [$targetModel, $targetStorage];
        }

        if (preg_match('/^(.*?)\s+(\d+\s*gb)$/i', trim($targetModel), $matches) === 1) {
            return [trim($matches[1]), strtoupper(str_replace(' ', '', $matches[2]))];
        }

        return [$targetModel, '128GB'];
    }

    private function findPrice(string $model, string $storage): ?TrocPhonePrice
    {
        $normalizedModel = $this->normalize($model);
        $normalizedStorage = $this->normalize($storage);

        // 1) Recherche exacte (normalisation SQL pour eviter de charger toute la table)
        $exactMatch = TrocPhonePrice::query()
            ->whereRaw("LOWER(REPLACE(model, ' ', '')) = ?", [$normalizedModel])
            ->whereRaw("LOWER(REPLACE(storage, ' ', '')) = ?", [$normalizedStorage])
            ->first();

        if ($exactMatch !== null) {
            return $exactMatch;
        }

        Log::info('troc.price_search.exact_not_found', [
            'model' => $model,
            'storage' => $storage,
        ]);

        // 2) Fallback: meme modele, capacite differente (ordre: 128GB > 256GB > 512GB > 64GB > 1TB)
        $modelOnlyCandidates = TrocPhonePrice::query()
            ->whereRaw("LOWER(REPLACE(model, ' ', '')) = ?", [$normalizedModel])
            ->get();

        if ($modelOnlyCandidates->isNotEmpty()) {
            $storagePreference = ['128gb', '256gb', '512gb', '64gb', '1tb'];
            foreach ($storagePreference as $preferred) {
                $candidate = $modelOnlyCandidates->first(function (TrocPhonePrice $price) use ($preferred): bool {
                    return $this->normalize((string) $price->storage) === $preferred;
                });
                if ($candidate !== null) {
                    Log::info('troc.price_search.model_match_fallback', [
                        'model' => $model,
                        'requested_storage' => $storage,
                        'matched_storage' => $candidate->storage,
                    ]);
                    return $candidate;
                }
            }
            $fallback = $modelOnlyCandidates->first();
            Log::info('troc.price_search.model_match_fallback', [
                'model' => $model,
                'requested_storage' => $storage,
                'matched_storage' => $fallback->storage,
            ]);
            return $fallback;
        }

        Log::warning('troc.price_search.not_found', [
            'model' => $model,
            'storage' => $storage,
            'normalized_model' => $normalizedModel,
            'normalized_storage' => $normalizedStorage,
            'available_count' => TrocPhonePrice::query()->count(),
        ]);

        return null;
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