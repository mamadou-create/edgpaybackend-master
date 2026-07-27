<?php

namespace App\Services;

use App\Exceptions\AiraloApiException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\AiraloOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiraloOrdersService
{
    public function __construct(private readonly AiraloApiClientService $apiClient)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws AiraloApiException
     */
    public function createOrder(User $user, string $packageId, int $quantity = 1, ?string $description = null): array
    {
        return $this->processWalletOrderInternal($user, $packageId, $quantity, $description);
    }

    /**
     * Nouvelle entree metier demandee: achat eSIM via debit wallet utilisateur.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws InsufficientBalanceException
     * @throws AiraloApiException
     */
    public function processWalletOrder(User $user, string $packageId): array
    {
        return $this->processWalletOrderInternal($user, $packageId, 1, null);
    }

    /**
     * Retrieves installation material from Airalo on demand. It must not be
     * persisted locally because it contains eSIM activation credentials.
     *
     * @return array<string, string|null>
     *
     * @throws AiraloApiException
     */
    public function getInstallationInstructions(AiraloOrder $order): array
    {
        $airaloOrderReference = trim((string) ($order->airalo_order_code ?: $order->airalo_order_id));
        if ($airaloOrderReference === '') {
            throw new InvalidArgumentException('Les instructions d’installation ne sont pas disponibles pour cette commande.');
        }

        $orderLookupReference = $airaloOrderReference;
        try {
            $response = $this->apiClient->getOrder($orderLookupReference);
        } catch (AiraloApiException $exception) {
            $numericOrderId = trim((string) $order->airalo_order_id);
            if ($exception->statusCode() !== 404
                || $numericOrderId === ''
                || $numericOrderId === $orderLookupReference) {
                throw $exception;
            }

            $orderLookupReference = $numericOrderId;
            $response = $this->apiClient->getOrder($orderLookupReference);
        }
        $normalized = $this->normalizeOrderResponse($response);
        $airaloOrderId = $normalized['order_id'] ?? $order->airalo_order_id ?? $airaloOrderReference;
        $this->persistAiraloIdentifiers($order, $normalized);
        $orderIccid = $normalized['iccid'];

        if ($normalized['qrcode_url'] === null && $normalized['iccid'] !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSimInstructions($normalized['iccid']),
            );
        }

        if ($normalized['qrcode_url'] === null && $normalized['iccid'] !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSim($normalized['iccid']),
            );
        }

        if ($normalized['qrcode_url'] === null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetOrderSims($orderLookupReference),
            );
        }

        $associationIccid = $normalized['iccid'];
        if ($normalized['qrcode_url'] === null
            && $orderIccid === null
            && $associationIccid !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSimInstructions($associationIccid),
            );
        }

        if ($normalized['qrcode_url'] === null
            && $orderIccid === null
            && $associationIccid !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSim($associationIccid),
            );
        }

        if ($normalized['qrcode_url'] === null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetOrderInstructions($airaloOrderId),
            );
        }

        if ($normalized['qrcode_url'] === null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->findMatchingSimFromList($order, $airaloOrderId, $normalized['iccid']),
            );
        }

        if ($normalized['qrcode_url'] === null
            && $associationIccid === null
            && $normalized['iccid'] !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSimInstructions($normalized['iccid']),
            );
        }

        if ($normalized['qrcode_url'] === null
            && $associationIccid === null
            && $normalized['iccid'] !== null) {
            $normalized = $this->mergeInstallationResponse(
                $normalized,
                $this->tryGetSim($normalized['iccid']),
            );
        }

        if ($normalized['qrcode_url'] === null) {
            Log::warning('Airalo keys available', [
                'airalo_order_id' => $airaloOrderId,
                'keys' => array_keys($this->toArray($response['data'] ?? null)),
            ]);
        }

        if ($normalized['iccid'] === null) {
            $this->logLatestSimIds($airaloOrderId);
        }

        $this->persistAiraloIdentifiers($order, $normalized);

        $hasTechnicalInstructions = $normalized['qrcode_url'] !== null
            || $normalized['smdp_address'] !== null
            || $normalized['ac_code'] !== null;
        $guide = $hasTechnicalInstructions ? [] : $this->extractGuideData($response);
        $instructionsStatus = $hasTechnicalInstructions
            ? 'complete'
            : ($guide === [] ? 'unavailable' : 'partial');

        return [
            'order_id' => $normalized['order_id'] ?? $airaloOrderId,
            'iccid' => $normalized['iccid'] ?? null,
            'qrcode_url' => $normalized['qrcode_url'] ?? null,
            'smdp_address' => $normalized['smdp_address'] ?? null,
            'ac_code' => $normalized['ac_code'] ?? null,
            'instructions_status' => $instructionsStatus,
            'guide_url' => $guide['guide_url'] ?? null,
            'instructions_html' => $guide['instructions_html'] ?? null,
            '_debug_airalo_response' => $this->redactInstallationPayload($response),
        ];
    }

    /**
     * Retrieves package and recharge history without exposing the ICCID in the
     * response. Unlimited plans do not expose meaningless zero balances.
     *
     * @return array<int, array<string, mixed>>
     * @throws AiraloApiException
     */
    public function getSimPackageHistory(AiraloOrder $order): array
    {
        $iccid = $order->iccid;
        if (!is_string($iccid) || trim($iccid) === '') {
            $this->getInstallationInstructions($order);
            $order->refresh();
            $iccid = $order->iccid;
        }

        if (!is_string($iccid) || trim($iccid) === '') {
            throw new InvalidArgumentException('L’historique est indisponible tant que la SIM Airalo n’est pas associée à cette commande.');
        }

        $response = $this->apiClient->getSimPackages($iccid);
        $data = $this->toArray($response['data'] ?? null);
        $packages = $this->toArray($data['packages'] ?? null);
        if ($packages === []) {
            $packages = $data;
        }
        if (!array_is_list($packages)) {
            $packages = [$packages];
        }

        return array_values(array_map(function (mixed $package): array {
            $entry = $this->toArray($package);
            if (($entry['is_unlimited'] ?? false) === true) {
                unset($entry['remaining'], $entry['total'], $entry['amount']);
            }

            return $entry;
        }, $packages));
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function persistAiraloIdentifiers(AiraloOrder $order, array $normalized): void
    {
        $updates = [];
        if (($order->airalo_order_code === null || $order->airalo_order_code === '')
            && ($normalized['order_code'] ?? null) !== null) {
            $updates['airalo_order_code'] = $normalized['order_code'];
        }
        if (($order->iccid === null || $order->iccid === '') && ($normalized['iccid'] ?? null) !== null) {
            $updates['iccid'] = $normalized['iccid'];
        }

        if ($updates !== []) {
            $order->forceFill($updates)->save();
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, string>
     */
    private function extractGuideData(array $response): array
    {
        $order = $this->extractOrderPayload($response);
        $guides = $this->toArray($order['installation_guides'] ?? null);
        $guideUrl = $this->firstString($guides, ['en', 'pdf']);
        $instructionsHtml = $this->firstString(
            $order,
            ['qrcode_installation', 'manual_installation'],
        );

        return array_filter([
            'guide_url' => $guideUrl,
            'instructions_html' => $instructionsHtml,
        ]);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed>|null $response
     * @return array<string, mixed>
     */
    private function mergeInstallationResponse(array $base, ?array $response): array
    {
        if ($response === null) {
            return $base;
        }

        $candidate = $this->normalizeOrderResponse($response);
        foreach (['iccid', 'qrcode_url', 'smdp_address', 'ac_code'] as $key) {
            if (($base[$key] ?? null) === null && ($candidate[$key] ?? null) !== null) {
                $base[$key] = $candidate[$key];
            }
        }

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryGetSimInstructions(string $iccid): ?array
    {
        try {
            return $this->apiClient->getSimInstructions($iccid);
        } catch (AiraloApiException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryGetSim(string $iccid): ?array
    {
        try {
            return $this->apiClient->getSim($iccid);
        } catch (AiraloApiException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryGetOrderInstructions(string $airaloOrderId): ?array
    {
        try {
            return $this->apiClient->getOrderInstructions($airaloOrderId);
        } catch (AiraloApiException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryGetOrderSims(string $airaloOrderId): ?array
    {
        try {
            return $this->apiClient->getOrderSims($airaloOrderId);
        } catch (AiraloApiException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMatchingSimFromList(AiraloOrder $order, string $airaloOrderId, ?string $iccid): ?array
    {
        try {
            $response = $this->apiClient->getSims([
                'filter[order_id]' => $airaloOrderId,
                'order_id' => $airaloOrderId,
                'limit' => 10,
            ]);
        } catch (AiraloApiException) {
            return null;
        }

        $data = $this->toArray($response['data'] ?? null);
        $candidates = $this->toArray($data['sims'] ?? null);
        if ($candidates === []) {
            $candidates = $data;
        }
        if (!array_is_list($candidates)) {
            $candidates = [$candidates];
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateIccid = $this->firstString($candidate, ['iccid']);
            if ($iccid !== null && $candidateIccid === $iccid) {
                return ['data' => $candidate];
            }

            $candidateOrderId = $this->firstScalarString($candidate, ['order_id', 'orderId']);
            $nestedOrder = $this->toArray($candidate['order'] ?? null);
            $candidateOrderId ??= $this->firstScalarString($nestedOrder, ['id', 'order_id']);
            if ($candidateOrderId === $airaloOrderId) {
                return ['data' => $candidate];
            }
        }

        return null;
    }

    private function logLatestSimIds(string $airaloOrderId): void
    {
        try {
            $response = $this->apiClient->getSims(['limit' => 5]);
        } catch (AiraloApiException) {
            return;
        }

        $data = $this->toArray($response['data'] ?? null);
        $candidates = $this->toArray($data['sims'] ?? null);
        if ($candidates === []) {
            $candidates = $data;
        }
        if (!array_is_list($candidates)) {
            $candidates = [$candidates];
        }

        $iccids = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $iccid = $this->firstString($candidate, ['iccid']);
            if ($iccid !== null) {
                $iccids[] = $iccid;
            }
        }

        Log::warning('Airalo latest SIMs IDs', [
            'airalo_order_id' => $airaloOrderId,
            'iccids' => array_slice($iccids, 0, 5),
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws InsufficientBalanceException
     * @throws AiraloApiException
     */
    private function processWalletOrderInternal(User $user, string $packageId, int $quantity = 1, ?string $description = null): array
    {
        $normalizedPackageId = trim($packageId);
        if ($normalizedPackageId === '') {
            throw new InvalidArgumentException('Le package_id est obligatoire.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('La quantite doit etre superieure ou egale a 1.');
        }

        $package = $this->findPackageById($normalizedPackageId);
        if ($package === null) {
            throw new InvalidArgumentException('Le forfait eSIM demande est introuvable ou inactif.');
        }

        $unitPrice = $this->extractPackagePrice($package);
        if ($unitPrice === null || $unitPrice <= 0) {
            throw new InvalidArgumentException('Prix du forfait eSIM invalide ou indisponible.');
        }

        $unitPriceGnf = $this->convertToGnf(
            $unitPrice,
            $this->extractPackageCurrency($package),
        );
        $totalAmount = $unitPriceGnf * $quantity;
        if ($totalAmount <= 0) {
            throw new InvalidArgumentException('Montant total de la commande eSIM invalide.');
        }

        return DB::transaction(function () use (
            $user,
            $normalizedPackageId,
            $quantity,
            $description,
            $unitPrice,
            $totalAmount,
            $package,
        ): array {
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw new InvalidArgumentException('Wallet utilisateur introuvable.');
            }

            $availableBalance = (int) $wallet->cash_available - (int) $wallet->blocked_amount;
            if ($availableBalance < $totalAmount) {
                throw new InsufficientBalanceException($availableBalance, $totalAmount);
            }

            $balanceBefore = (int) $wallet->cash_available;
            $wallet->cash_available = $balanceBefore - $totalAmount;
            $wallet->save();

            $user->solde_portefeuille = max(0, (int) $wallet->cash_available);
            $user->save();

            $response = $this->apiClient->createOrder($normalizedPackageId, $quantity, $description);
            $normalized = $this->normalizeOrderResponse($response);
            $airaloOrderId = $normalized['order_id'] ?? null;
            if ($airaloOrderId === null || $airaloOrderId === '') {
                throw new InvalidArgumentException('Airalo n’a pas retourné d’identifiant de commande valide.');
            }

            AiraloOrder::query()->create([
                'user_id' => $user->id,
                'package_id' => $normalizedPackageId,
                'package_title' => $this->packageTitle($package),
                'destination' => $this->packageDestination($package),
                'data_volume' => $this->packageDataVolume($package),
                'validity_days' => $this->packageValidityDays($package),
                'operator_name' => $this->packageOperatorName($package),
                'airalo_order_id' => $airaloOrderId,
                'airalo_order_code' => $normalized['order_code'] ?? null,
                'iccid' => $normalized['iccid'] ?? null,
                'quantity' => $quantity,
                'price' => $unitPrice,
                'currency' => $this->extractPackageCurrency($package),
                'status' => 'completed',
                'error_message' => null,
            ]);

            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'amount' => -$totalAmount,
                'type' => 'debit',
                'reference' => 'airalo_' . Str::uuid()->toString(),
                'description' => 'Achat forfait eSIM Airalo: ' . $normalizedPackageId,
                'metadata' => [
                    'payment_status' => 'completed',
                    'package_id' => $normalizedPackageId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'currency' => $this->extractPackageCurrency($package),
                    'source' => 'airalo_esim_order',
                ],
            ]);

            $normalized['wallet_debited_amount'] = $totalAmount;
            $normalized['wallet_balance_before'] = $balanceBefore;
            $normalized['wallet_balance_after'] = (int) $wallet->cash_available;

            Log::info('Airalo order created.', [
                'order_id' => $normalized['order_id'] ?? null,
                'package_id' => $normalizedPackageId,
                'status' => 'completed',
            ]);

            return $normalized;
        });
    }

    /**
     * @throws AiraloApiException
     */
    private function findPackageById(string $packageId): ?array
    {
        $packagesResponse = $this->apiClient->getPackages();
        $packages = $packagesResponse['data'] ?? [];

        if (!is_array($packages)) {
            return null;
        }

        foreach ($this->extractOrderablePackages($packages) as $package) {
            if (!is_array($package)) {
                continue;
            }

            $candidateId = (string) ($package['id'] ?? $package['package_id'] ?? '');
            if ($candidateId === $packageId) {
                return $package;
            }
        }

        return null;
    }

    private function extractPackagePrice(array $package): ?float
    {
        $price = $package['price'] ?? $package['cost_price'] ?? $package['costPrice'] ?? null;
        if (is_numeric($price)) {
            return (float) $price;
        }

        if (isset($package['prices']) && is_array($package['prices'])) {
            foreach ($package['prices'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $value = $entry['price'] ?? $entry['amount'] ?? $entry['value'] ?? null;
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return null;
    }

    private function extractPackageCurrency(array $package): string
    {
        $currency = $package['currency'] ?? $package['price_currency'] ?? 'USD';

        if ((!is_string($currency) || trim($currency) === '') && isset($package['prices']) && is_array($package['prices'])) {
            foreach ($package['prices'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $entryCurrency = $entry['currency'] ?? $entry['price_currency'] ?? null;
                if (is_string($entryCurrency) && trim($entryCurrency) !== '') {
                    $currency = $entryCurrency;
                    break;
                }
            }
        }

        return strtoupper((string) $currency);
    }

    private function packageTitle(array $package): ?string
    {
        return $this->firstString($package, ['title', 'name']);
    }

    private function packageDestination(array $package): ?string
    {
        return $this->firstString($package, [
            'country_name',
            'countryName',
            'destination',
            'country_code',
            'iso_code',
        ]);
    }

    private function packageDataVolume(array $package): ?string
    {
        $value = $package['data']
            ?? $package['data_amount']
            ?? $package['amount']
            ?? null;
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $unit = strtoupper(trim((string) ($package['data_unit'] ?? 'GB')));
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' ' . $unit;
        }

        return trim((string) $value);
    }

    private function packageValidityDays(array $package): ?int
    {
        $value = $package['validity_days'] ?? $package['day'] ?? $package['days'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function packageOperatorName(array $package): ?string
    {
        $operator = $package['operator_name'] ?? $package['operator'] ?? null;
        if (is_array($operator)) {
            $operator = $operator['name'] ?? $operator['title'] ?? null;
        }

        return is_string($operator) && trim($operator) !== '' ? trim($operator) : null;
    }

    private function convertToGnf(float $amount, string $currency): int
    {
        if ($currency === 'GNF') {
            return (int) round($amount);
        }

        $rate = $currency === 'EUR'
            ? (float) config('services.airalo.eur_gnf_rate', 9300)
            : (float) config('services.airalo.gnf_rate', 8600);
        $marginPercent = max(0, (float) config('services.airalo.gnf_margin_percent', 0));

        return (int) round($amount * $rate * (1 + ($marginPercent / 100)));
    }

    /**
     * @param array<int, mixed> $catalog
     * @return array<int, array<string, mixed>>
     */
    private function extractOrderablePackages(array $catalog): array
    {
        $packages = [];

        foreach ($catalog as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['id']) || isset($entry['package_id'])) {
                $packages[] = $entry;
                continue;
            }

            $operators = $entry['operators'] ?? null;
            if (!is_array($operators)) {
                continue;
            }

            foreach ($operators as $operator) {
                if (!is_array($operator)) {
                    continue;
                }

                $operatorPackages = $operator['packages'] ?? null;
                if (!is_array($operatorPackages)) {
                    continue;
                }

                foreach ($operatorPackages as $package) {
                    if (!is_array($package)) {
                        continue;
                    }

                    $id = (string) ($package['id'] ?? $package['package_id'] ?? '');
                    if (trim($id) === '') {
                        continue;
                    }

                    $packages[] = [
                        ...$package,
                        'id' => $id,
                        'package_id' => (string) ($package['package_id'] ?? $id),
                        'country_code' => (string) ($entry['country_code'] ?? $entry['iso_code'] ?? ''),
                        'country_name' => (string) ($entry['title'] ?? $entry['name'] ?? ''),
                        'operator_name' => (string) ($operator['operator_name'] ?? $operator['name'] ?? ''),
                    ];
                }
            }
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizeOrderResponse(array $response): array
    {
        $order = $this->extractOrderPayload($response);
        $sims = $this->toArray($order['sims'] ?? null);
        $subscriptions = $this->toArray($order['subscriptions'] ?? null);
        $sources = [
            $this->toArray($sims[0] ?? null),
            $this->toArray($subscriptions[0] ?? null),
            $this->toArray($order['esim'] ?? null),
            $this->toArray($order['instructions'] ?? null),
            $this->toArray($order['installation'] ?? null),
            $this->toArray($order['install'] ?? null),
            $this->toArray($order['activation'] ?? null),
            $this->toArray($order['sim'] ?? null),
            $order,
        ];

        return [
            'order_id' => $this->firstScalarString($order, ['id', 'order_id', 'uuid', 'code']),
            'order_code' => $this->firstString($order, ['code']),
            'iccid' => $this->firstStringFromSources($sources, ['iccid']),
            'qrcode_url' => $this->firstStringFromSources(
                $sources,
                ['qrcode_url', 'qr_code_url', 'qrCodeUrl', 'qr_code', 'qrcode'],
            ),
            'smdp_address' => $this->firstStringFromSources(
                $sources,
                ['smdp_address', 'sm_dp_address', 'smdpAddress', 'direct_address'],
            ),
            'ac_code' => $this->firstStringFromSources(
                $sources,
                ['ac_code', 'activation_code', 'activationCode', 'matching_id'],
            ),
            'raw' => $response,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractOrderPayload(array $response): array
    {
        $data = $this->toArray($response['data'] ?? null);

        if ($data === []) {
            return $response;
        }

        $nestedOrder = $this->toArray($data['order'] ?? null);
        if ($nestedOrder !== []) {
            return $nestedOrder;
        }

        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     */
    private function firstStringFromSources(array $sources, array $keys): ?string
    {
        foreach ($sources as $source) {
            $value = $this->firstString($source, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Airalo order IDs can be UUID strings or numeric integers.
     *
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstScalarString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [];
    }

    /**
     * Keeps the response shape available for temporary diagnostics without
     * writing eSIM activation credentials to the application logs.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactInstallationPayload(array $payload): array
    {
        $sensitiveKeys = [
            'qrcode_url',
            'qr_code_url',
            'qr_code',
            'qrcode',
            'smdp_address',
            'sm_dp_address',
            'direct_address',
            'ac_code',
            'activation_code',
            'matching_id',
            'iccid',
        ];
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactInstallationPayload($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}
