<?php

namespace App\Services;

use App\Exceptions\AiraloApiException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\AiraloOrder;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
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

            AiraloOrder::query()->create([
                'user_id' => $user->id,
                'package_id' => $normalizedPackageId,
                'airalo_order_id' => $normalized['order_id'] ?? null,
                'iccid' => $normalized['iccid'] ?? null,
                'qrcode_url' => $normalized['qrcode_url'] ?? null,
                'smdp_address' => $normalized['smdp_address'] ?? null,
                'ac_code' => $normalized['ac_code'] ?? null,
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

    private function convertToGnf(float $amount, string $currency): int
    {
        return match ($currency) {
            'GNF' => (int) round($amount),
            'EUR' => (int) round($amount * 9300),
            default => (int) round($amount * 8600),
        };
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

        $installation = $this->toArray($order['installation'] ?? $order['install'] ?? $order['activation'] ?? null);
        $sim = $this->toArray($order['sim'] ?? $order['esim'] ?? null);

        if ($sim === []) {
            $sims = $order['sims'] ?? null;
            if (is_array($sims) && isset($sims[0]) && is_array($sims[0])) {
                $sim = $this->toArray($sims[0]);
            }
        }

        return [
            'order_id' => $this->firstString($order, ['order_id', 'id', 'uuid']),
            'iccid' => $this->firstString($sim, ['iccid']) ?: $this->firstString($order, ['iccid']),
            'qrcode_url' => $this->firstString($installation, ['qrcode_url', 'qr_code_url', 'qrCodeUrl'])
                ?: $this->firstString($sim, ['qrcode_url', 'qr_code_url', 'qrCodeUrl', 'qrcode'])
                ?: $this->firstString($order, ['qrcode_url', 'qr_code_url', 'qrCodeUrl', 'qrcode']),
            'smdp_address' => $this->firstString($installation, ['smdp_address', 'sm_dp_address', 'smdpAddress'])
                ?: $this->firstString($sim, ['smdp_address', 'sm_dp_address', 'smdpAddress'])
                ?: $this->firstString($order, ['smdp_address', 'sm_dp_address', 'smdpAddress']),
            'ac_code' => $this->firstString($installation, ['ac_code', 'activation_code', 'activationCode'])
                ?: $this->firstString($sim, ['ac_code', 'activation_code', 'activationCode'])
                ?: $this->firstString($order, ['ac_code', 'activation_code', 'activationCode']),
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

        if ($data !== []) {
            return $data;
        }

        return $response;
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
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [];
    }
}
