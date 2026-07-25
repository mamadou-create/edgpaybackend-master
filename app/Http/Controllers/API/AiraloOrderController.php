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
            $businessCode = $exception->statusCode() === 402
                ? 'AIRALO_INSUFFICIENT_CREDIT'
                : 'AIRALO_API_ERROR';

            return response()->json([
                'success' => false,
                'business_code' => $businessCode,
                'message' => 'Erreur Airalo lors de la creation de commande: ' . $exception->getMessage(),
            ], $exception->statusCode());
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
                'airalo_order_id',
                'iccid',
                'qrcode_url',
                'smdp_address',
                'ac_code',
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
            'data' => $order,
        ], 200);
    }
}
