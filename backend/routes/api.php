<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['tenant.context', 'tenant.resolve'])
    ->group(function (): void {
        Route::get('/tenant', [AuthController::class, 'tenant']);
        Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::get('/auth/me', [AuthController::class, 'me'])
            ->middleware(['auth:sanctum', 'tenant.member']);
    });
