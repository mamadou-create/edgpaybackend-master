<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AiraloApiException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\AiraloOrder;
use App\Services\AiraloOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AiraloOrderController extends Controller
{
    public function __construct(private readonly AiraloOrdersService $ordersService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package_id' => ['required', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifie.',
                ], 401);
            }

            $payload = $validator->validated();

            $order = $this->ordersService->createOrder(
                $user,
                (string) $payload['package_id'],
                (int) ($payload['quantity'] ?? 1),
                isset($payload['description']) ? (string) $payload['description'] : null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Commande eSIM créée.',
                'data' => [
                    'order_id' => $order['order_id'] ?? null,
                    'iccid' => $order['iccid'] ?? null,
                    'qrcode_url' => $order['qrcode_url'] ?? null,
                    'smdp_address' => $order['smdp_address'] ?? null,
                    'ac_code' => $order['ac_code'] ?? null,
                ],
            ], 200);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (InsufficientBalanceException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant dans votre Wallet Mding Pay.',
                'business_code' => $exception->businessCode(),
                'errors' => [
                    'available' => $exception->available(),
                    'required' => $exception->required(),
                ],
            ], 400);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'La commande eSIM n’a pas pu être créée.');
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors de la creation de la commande eSIM.',
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifie.',
            ], 401);
        }

        $orders = $user->airaloOrders()
            ->latest('created_at')
            ->get([
                'id',
                'package_id',
                'package_title',
                'destination',
                'data_volume',
                'validity_days',
                'operator_name',
                'airalo_order_id',
                'quantity',
                'price',
                'currency',
                'status',
                'error_message',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Historique eSIM récupéré.',
            'data' => $orders,
        ], 200);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifie.',
            ], 401);
        }

        $order = AiraloOrder::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($order === null) {
            return response()->json([
                'success' => false,
                'message' => 'Commande eSIM introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Commande eSIM récupérée.',
            'data' => $order,
        ], 200);
    }

    public function instructions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifie.',
            ], 401);
        }

        $order = AiraloOrder::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($order === null) {
            return response()->json([
                'success' => false,
                'message' => 'Commande eSIM introuvable.',
            ], 404);
        }

        try {
            $instructions = $this->ordersService->getInstallationInstructions($order);
            $airaloResponse = $instructions['_debug_airalo_response'] ?? [];
            unset($instructions['_debug_airalo_response']);
            Log::info('Airalo eSIM installation instructions retrieved.', [
                'local_order_id' => $order->id,
                'airalo_order_id' => $order->airalo_order_id,
                'airalo_status' => 200,
                'airalo_response' => $airaloResponse,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Instructions d’installation eSIM récupérées.',
                'data' => $instructions,
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 404);
        } catch (AiraloApiException $exception) {
            Log::error('Airalo eSIM installation instructions retrieval failed.', [
                'local_order_id' => $order->id,
                'airalo_order_id' => $order->airalo_order_id,
                'airalo_status' => $exception->statusCode(),
                'airalo_response' => $exception->payload(),
                'airalo_message' => $exception->airaloMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => $exception->statusCode(),
                'message' => 'Les instructions d’installation ne sont pas disponibles pour le moment.',
                'debug_message' => sprintf(
                    'Airalo HTTP %d: %s',
                    $exception->statusCode(),
                    $exception->airaloMessage(),
                ),
                'code' => $exception->errorCode(),
            ], $exception->statusCode());
        }
    }

    public function packageHistory(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Utilisateur non authentifie.'], 401);
        }

        $order = AiraloOrder::query()->where('id', $id)->where('user_id', $user->id)->first();
        if ($order === null) {
            return response()->json(['success' => false, 'message' => 'Commande eSIM introuvable.'], 404);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Historique des forfaits eSIM récupéré.',
                'data' => $this->ordersService->getSimPackageHistory($order),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 404);
        } catch (AiraloApiException $exception) {
            return $this->airaloError($exception, 'Historique eSIM indisponible pour le moment.');
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
