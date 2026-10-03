<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\Auth\ApiTokenController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public authentication endpoints
    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('login', [SessionController::class, 'store'])->name('login');
        Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
    });

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        // Current user / security
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
            Route::get('me', [CurrentUserController::class, 'show'])->name('me');
            Route::put('me', [CurrentUserController::class, 'update'])->name('me.update');
            Route::put('password', [PasswordController::class, 'update'])->name('password.update');
            Route::post('two-factor', [TwoFactorController::class, 'store'])->name('two-factor.enable');
            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::delete('two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.disable');
            Route::get('tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
            Route::post('tokens', [ApiTokenController::class, 'store'])->name('tokens.store');
            Route::delete('tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('tokens.destroy');
        });

        // Tenant-scoped administration
        Route::middleware('tenant.required')->group(function () {
            Route::apiResource('users', UserController::class);
            Route::apiResource('roles', RoleController::class)->except('show');
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::get('audit/activities', [AuditController::class, 'activities'])->name('audit.activities');
            Route::get('audit/logins', [AuditController::class, 'logins'])->name('audit.logins');
            Route::get('settings', [TenantSettingsController::class, 'show'])->name('settings.show');
            Route::post('settings', [TenantSettingsController::class, 'update'])->name('settings.update');

            // Master data (reference lists). {type} must be registered in config/master_data.php
            Route::prefix('master-data')->name('master-data.')->whereIn('type', array_keys(config('master_data.types')))->group(function () {
                Route::get('/', [MasterDataController::class, 'types'])->name('types');
                Route::get('{type}', [MasterDataController::class, 'index'])->name('index');
                Route::post('{type}', [MasterDataController::class, 'store'])->name('store');
                Route::put('{type}/{item}', [MasterDataController::class, 'update'])->name('update');
                Route::delete('{type}/{item}', [MasterDataController::class, 'destroy'])->name('destroy');
            });
        });

        // Platform administration
        Route::prefix('platform')->name('platform.')->middleware('platform.admin')->group(function () {
            Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'update']);
        });
    });
});
