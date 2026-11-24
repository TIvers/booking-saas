<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DemoPaymentController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SlotController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\TenantOnboardingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::post('/onboarding', [TenantOnboardingController::class, 'store'])
            ->middleware('throttle:3,1');
        Route::get('/tenant', [AuthController::class, 'tenant']);
        Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
        Route::get('/auth/me', [AuthController::class, 'me'])
            ->middleware(['auth:sanctum', 'tenant.member']);

        Route::get('/locations', [LocationController::class, 'index']);
        Route::get('/locations/{location}', [LocationController::class, 'show']);
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{service}', [ServiceController::class, 'show']);
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/{staff}', [StaffController::class, 'show']);
        Route::get('/slots', [SlotController::class, 'index']);

        Route::middleware(['auth:sanctum', 'tenant.member', 'tenant.manager'])->group(function (): void {
            Route::get('/manage/dashboard', DashboardController::class);
            Route::get('/manage/services', [ServiceController::class, 'manageIndex']);
            Route::get('/manage/staff', [StaffController::class, 'manageIndex']);

            Route::post('/locations', [LocationController::class, 'store']);
            Route::patch('/locations/{location}', [LocationController::class, 'update']);

            Route::post('/services', [ServiceController::class, 'store']);
            Route::patch('/services/{service}', [ServiceController::class, 'update']);
            Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

            Route::post('/staff', [StaffController::class, 'store']);
            Route::patch('/staff/{staff}', [StaffController::class, 'update']);
            Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);

            Route::get('/staff/{staff}/schedule', [ScheduleController::class, 'show']);
            Route::put('/staff/{staff}/schedule', [ScheduleController::class, 'update']);
            Route::put('/staff/{staff}/schedule/exceptions/{date}', [ScheduleController::class, 'updateException']);
            Route::delete('/staff/{staff}/schedule/exceptions/{date}', [ScheduleController::class, 'destroyException']);

            Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        });

        Route::middleware(['auth:sanctum', 'tenant.member'])->group(function (): void {
            Route::get('/bookings', [BookingController::class, 'index']);
            Route::post('/bookings', [BookingController::class, 'store']);
            Route::get('/bookings/{booking}', [BookingController::class, 'show']);
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
            Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
            Route::post('/bookings/{booking}/demo-payment', [DemoPaymentController::class, 'store']);
            Route::post('/bookings/{booking}/demo-payment/result', [DemoPaymentController::class, 'complete']);
        });
    });
