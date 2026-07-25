<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AiraloApiException;
use App\Http\Controllers\Controller;
use App\Services\AiraloPackagesService;
use Illuminate\Http\JsonResponse;

class AiraloPackageController extends Controller
{
    public function __construct(private readonly AiraloPackagesService $packagesService)
    {
    }

    public function country(string $countryCode): JsonResponse
    {
        $normalizedCountryCode = strtoupper(trim($countryCode));

        if (preg_match('/^[A-Z]{2,3}$/', $normalizedCountryCode) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Code pays invalide. Utilisez un code ISO alpha-2 ou alpha-3 (ex: SN, FR, CIV).',
            ], 400);
        }

        try {
            $packages = $this->packagesService->getPackagesByCountry($normalizedCountryCode);

            return response()->json([
                'success' => true,
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des forfaits Airalo: ' . $exception->getMessage(),
            ], 500);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la récupération des forfaits.',
            ], 500);
        }
    }

    public function countries(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->packagesService->getCountries(),
            ]);
        } catch (AiraloApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des destinations Airalo: ' . $exception->getMessage(),
            ], 500);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la récupération des destinations.',
            ], 500);
        }
    }

    public function global(): JsonResponse
    {
        try {
            $packages = $this->packagesService->getGlobalPackages();

            return response()->json([
                'success' => true,
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des forfaits globaux Airalo: ' . $exception->getMessage(),
            ], 500);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la récupération des forfaits globaux.',
            ], 500);
        }
    }

    public function regional(): JsonResponse
    {
        try {
            $packages = $this->packagesService->getRegionalPackages();

            return response()->json([
                'success' => true,
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des forfaits régionaux Airalo: ' . $exception->getMessage(),
            ], 500);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la récupération des forfaits régionaux.',
            ], 500);
        }
    }
}
