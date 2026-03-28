<?php

namespace App\Http\Controllers\Troc;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\TrocPhonePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrocController extends Controller
{
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

        $estimatedPrice = max(0, round((float) $price->base_price - $deductions['total'], 2));
        $convertedBasePrice = $this->convertUsdToGnf((float) $price->base_price);
        $convertedEstimatedPrice = $this->convertUsdToGnf($estimatedPrice);
        $convertedTotalDeduction = $this->convertUsdToGnf($deductions['total']);

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

        $targetPriceGnf = $this->convertUsdToGnf((float) $targetPrice->base_price);
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

    private function convertDeductionItemsToGnf(array $items): array
    {
        return array_map(function (array $item): array {
            return [
                'label' => $item['label'] ?? '',
                'amount' => $this->convertUsdToGnf((float) ($item['amount'] ?? 0)),
            ];
        }, $items);
    }

    private function convertUsdToGnf(float $amount): float
    {
        $rate = max(1, (int) config('troc.usd_to_gnf_rate', 8700));

        return round($amount * $rate, 0);
    }

    private function formatGnf(float $amount): string
    {
        return number_format(round($amount, 0), 0, '.', ' ') . ' ' . config('troc.display_currency', 'GNF');
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

        return TrocPhonePrice::query()
            ->get()
            ->first(function (TrocPhonePrice $price) use ($normalizedModel, $normalizedStorage): bool {
                return $this->normalize((string) $price->model) === $normalizedModel
                    && $this->normalize((string) $price->storage) === $normalizedStorage;
            });
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