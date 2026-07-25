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
                'message' => 'Forfaits eSIM récupérés.',
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'Les forfaits eSIM sont indisponibles.');
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
                'message' => 'Destinations eSIM récupérées.',
                'data' => $this->packagesService->getCountries(),
            ]);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'Les destinations eSIM sont indisponibles.');
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
                'message' => 'Forfaits mondiaux récupérés.',
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'Les forfaits mondiaux sont indisponibles.');
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
                'message' => 'Forfaits régionaux récupérés.',
                'data' => array_map(static fn ($package): array => $package->toArray(), $packages),
            ], 200);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'Les forfaits régionaux sont indisponibles.');
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la récupération des forfaits régionaux.',
            ], 500);
        }
    }

    private function airaloError(AiraloApiException $exception, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => $exception->statusCode(),
            'message' => $message,
            'airalo_message' => $exception->airaloMessage(),
            'code' => $exception->errorCode(),
        ], $exception->statusCode());
    }
}
