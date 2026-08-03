<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\CurrencyConversionRate;
use App\Services\CurrencyConversionRateService;
use Illuminate\Http\Request;

class CurrencyConversionRateController extends Controller
{
    public function __construct(private CurrencyConversionRateService $rates) {}

    public function index(Request $request)
    {
        if (!$this->isAuthorized($request, 'view_conversion_audit')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');

        return ApiResponseClass::sendResponse(
            CurrencyConversionRate::query()->with(['creator:id,display_name', 'approver:id,display_name'])->latest()->get(),
            'Taux de conversion récupérés',
        );
    }

    public function store(Request $request)
    {
        if (!$this->isAuthorized($request, 'create_conversion_rate')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');
        $data = $request->validate([
            'from_currency' => 'required|string|size:3|different:to_currency',
            'to_currency' => 'required|string|size:3',
            'rate' => 'required|numeric|gt:0',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
        ]);

        return ApiResponseClass::sendResponse($this->rates->create($request->user(), $data), 'Taux créé en brouillon', 201);
    }

    public function activate(Request $request, CurrencyConversionRate $rate)
    {
        if (!$this->isAuthorized($request, 'activate_conversion_rate')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');
        try {
            return ApiResponseClass::sendResponse($this->rates->activate($rate, $request->user()), 'Taux activé');
        } catch (\DomainException $exception) {
            return ApiResponseClass::sendError($exception->getMessage(), null, 422, 'RATE_EXPIRED');
        } catch (\LogicException $exception) {
            return ApiResponseClass::sendError($exception->getMessage(), null, 422, 'RATE_NOT_APPROVED');
        }
    }

    public function approve(Request $request, CurrencyConversionRate $rate)
    {
        if (!$this->isAuthorized($request, 'approve_conversion_rate')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');
        try {
            return ApiResponseClass::sendResponse($this->rates->approve($rate, $request->user()), 'Taux approuvé');
        } catch (\DomainException $exception) {
            return ApiResponseClass::sendError($exception->getMessage(), null, 422, 'RATE_EXPIRED');
        } catch (\LogicException $exception) {
            return ApiResponseClass::sendError($exception->getMessage(), null, 422, 'RATE_NOT_APPROVABLE');
        }
    }

    public function deactivate(Request $request, CurrencyConversionRate $rate)
    {
        if (!$this->isAuthorized($request, 'deactivate_conversion_rate')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');

        return ApiResponseClass::sendResponse($this->rates->deactivate($rate, $request->user()), 'Taux désactivé');
    }

    public function history(Request $request, CurrencyConversionRate $rate)
    {
        if (!$this->isAuthorized($request, 'view_conversion_audit')) return ApiResponseClass::forbidden('Accès réservé au super admin autorisé.');

        return ApiResponseClass::sendResponse($rate->history()->with('actor:id,display_name')->latest()->get(), 'Historique du taux récupéré');
    }

    private function isAuthorized(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user?->isSuperAdmin() === true && $user->role?->hasPermission($permission) === true;
    }
}