<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\TradeMatch;
use App\Models\UsedItemListing;
use Illuminate\Http\JsonResponse;

class TradeMatchController extends Controller
{
    public function index(UsedItemListing $listing): JsonResponse
    {
        $matches = TradeMatch::query()
            ->where('listing_id', $listing->id)
            ->with(['candidateListing:id,title,category,condition_label,city,transaction_type'])
            ->orderByDesc('compatibility_score')
            ->limit(100)
            ->get();

        return ApiResponseClass::sendResponse($matches, 'Compatibilités récupérées avec succès');
    }
}
