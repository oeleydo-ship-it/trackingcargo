<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Platform\GatewayController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/stripe', [GatewayController::class, 'webhook']);

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/login', [AuthTokenController::class, 'store'])->middleware('throttle:10,1')->name('login');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/me', [AuthTokenController::class, 'show'])->name('me');
        Route::delete('/logout', [AuthTokenController::class, 'destroy'])->name('logout');
    });
});
