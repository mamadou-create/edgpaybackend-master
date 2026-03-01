<?php

use App\Http\Controllers\API\AuthController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API Laravel en ligne',
        'version' => '1.0.0'
    ]);
});


Route::controller(AuthController::class)->group(function () {
    Route::get('login', 'unauthenticated')->name('login');
});