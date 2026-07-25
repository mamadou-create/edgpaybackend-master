<?php

namespace App\Http\Controllers\API;

use App\Exceptions\AiraloApiException;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\AiraloOrder;
use App\Services\AiraloOrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'message' => $exception->getMessage(),
                'business_code' => $exception->businessCode(),
                'errors' => [
                    'available' => $exception->available(),
                    'required' => $exception->required(),
                ],
            ], $exception->statusCode());
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
