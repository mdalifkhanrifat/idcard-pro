<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\Auth\ApiTokenController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\BiometricCaptureController;
use App\Http\Controllers\Api\V1\CardBiometricImageController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardholderController;
use App\Http\Controllers\Api\V1\CardholderFileController;
use App\Http\Controllers\Api\V1\CardholderImportController;
use App\Http\Controllers\Api\V1\CardPdfController;
use App\Http\Controllers\Api\V1\CardPrintController;
use App\Http\Controllers\Api\V1\CardRenderController;
use App\Http\Controllers\Api\V1\CardRequirementController;
use App\Http\Controllers\Api\V1\CardTemplateController;
use App\Http\Controllers\Api\V1\CardTemplateElementController;
use App\Http\Controllers\Api\V1\CardVerificationController;
use App\Http\Controllers\Api\V1\CustomFieldController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\Platform\TenantController;
use App\Http\Controllers\Api\V1\QualificationController;
use App\Http\Controllers\Api\V1\Reports\CardPrintReportController;
use App\Http\Controllers\Api\V1\Reports\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SignatoryController;
use App\Http\Controllers\Api\V1\TenantFontController;
use App\Http\Controllers\Api\V1\TenantImageController;
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

    // Signed, short-lived links to cardholder files (authorization happened when the link was issued).
    // Rate limit `signed-files`: a whole print batch loads several of these per card.
    Route::get('cardholder-files/{file}', [CardholderFileController::class, 'content'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('cardholder-files.content');
    Route::get('signatory-signatures/{signatory}', [SignatoryController::class, 'signature'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('signatory-signatures.show');
    Route::get('card-template-images/{template}/{image}', [CardTemplateElementController::class, 'image'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('card-template-images.show');
    Route::get('font-files/{font}', [TenantFontController::class, 'file'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('font-files.show');
    Route::get('library-image-files/{image}', [TenantImageController::class, 'file'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('library-image-files.show');
    // Decrypted biometric images (authorization happened when the link was issued; never cached)
    Route::get('biometric-images/{capture}', [BiometricCaptureController::class, 'image'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('biometric-images.show');
    // Biometric images an issued card keeps as its own copy (4.10)
    Route::get('card-biometric-images/{image}', [CardBiometricImageController::class, 'show'])
        ->middleware(['signed:relative', 'throttle:signed-files'])
        ->name('card-biometric-images.show');

    // Public card verification (3.7): the code from the card's QR code, no sign-in
    Route::get('verify/{code}', [CardVerificationController::class, 'show'])
        ->middleware('throttle:'.(int) config('cards.verification.throttle').',1')
        ->name('cards.verify');

    // Cards for the PDF engine's render page (signed link made by CardRenderService::link)
    Route::get('card-render', [CardRenderController::class, 'show'])
        ->middleware(['signed:relative', 'throttle:120,1'])
        ->name('card-render');

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
            Route::get('settings/card-number-preview', [TenantSettingsController::class, 'cardNumberPreview'])->name('settings.card-number-preview');

            // Master data (reference lists). {type} must be registered in config/master_data.php
            Route::prefix('master-data')->name('master-data.')->whereIn('type', array_keys(config('master_data.types')))->group(function () {
                Route::get('/', [MasterDataController::class, 'types'])->name('types');
                Route::get('{type}', [MasterDataController::class, 'index'])->name('index');
                Route::post('{type}', [MasterDataController::class, 'store'])->name('store');
                Route::put('{type}/{item}', [MasterDataController::class, 'update'])->name('update');
                Route::delete('{type}/{item}', [MasterDataController::class, 'destroy'])->name('destroy');
            });

            Route::get('custom-fields/presets', [CustomFieldController::class, 'presets'])->name('custom-fields.presets');
            Route::post('custom-fields/presets/{preset}', [CustomFieldController::class, 'applyPreset'])->name('custom-fields.presets.apply');
            Route::apiResource('custom-fields', CustomFieldController::class)->except('show');

            Route::prefix('cardholder-imports')->name('cardholder-imports.')->controller(CardholderImportController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('template', 'template')->name('template');
                Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
                Route::get('{import}', 'show')->name('show');
                Route::post('{import}/start', 'start')->name('start');
                Route::delete('{import}', 'destroy')->name('destroy');
            });

            Route::apiResource('cardholders', CardholderController::class);

            Route::apiResource('card-templates', CardTemplateController::class)->parameters(['card-templates' => 'template']);
            Route::post('card-templates/{template}/duplicate', [CardTemplateController::class, 'duplicate'])->name('card-templates.duplicate');
            Route::get('card-templates/{template}/elements', [CardTemplateElementController::class, 'index'])->name('card-templates.elements.index');
            Route::put('card-templates/{template}/elements', [CardTemplateElementController::class, 'update'])->name('card-templates.elements.update');
            Route::post('card-templates/{template}/images', [CardTemplateElementController::class, 'uploadImage'])->name('card-templates.images.store');
            Route::get('card-templates/{template}/preview', [CardTemplateElementController::class, 'preview'])->name('card-templates.preview');

            Route::apiResource('signatories', SignatoryController::class);
            Route::apiResource('fonts', TenantFontController::class)->only(['index', 'store', 'destroy']);
            Route::apiResource('library-images', TenantImageController::class)->except('show')->parameters(['library-images' => 'image']);
            Route::post('library-images/{image}/replace', [TenantImageController::class, 'replace'])->name('library-images.replace');
            Route::post('signatories/{signatory}/signature', [SignatoryController::class, 'uploadSignature'])->name('signatories.signature.store');
            Route::delete('signatories/{signatory}/signature', [SignatoryController::class, 'removeSignature'])->name('signatories.signature.destroy');
            Route::apiResource('cardholders.files', CardholderFileController::class)->only(['index', 'store', 'destroy'])->scoped(['cardholder' => 'public_id', 'file' => 'public_id']);
            Route::apiResource('cardholders.family-members', FamilyMemberController::class)->except('show')->scoped(['cardholder' => 'public_id', 'family_member' => 'public_id']);
            Route::post('cardholders/{cardholder}/biometrics/unavailable', [BiometricCaptureController::class, 'unavailable'])->name('cardholders.biometrics.unavailable');
            Route::apiResource('cardholders.biometrics', BiometricCaptureController::class)->only(['index', 'store', 'destroy'])->parameters(['biometrics' => 'biometric_capture'])->scoped(['cardholder' => 'public_id', 'biometric_capture' => 'public_id']);
            Route::get('cardholders/{cardholder}/cards/next-number', [CardController::class, 'nextNumber'])->name('cardholders.cards.next-number');
            Route::get('cardholders/{cardholder}/card-requirements', [CardRequirementController::class, 'show'])->name('cardholders.card-requirements');
            Route::apiResource('cardholders.cards', CardController::class)->only(['index', 'store'])->scoped(['cardholder' => 'public_id']);
            Route::get('cards', [CardController::class, 'list'])->name('cards.index');
            Route::get('cards/summary', [CardController::class, 'summary'])->name('cards.summary');
            Route::get('cards/options', [CardController::class, 'options'])->name('cards.options');
            Route::get('cards/printable', [CardPrintController::class, 'printable'])->name('cards.printable');
            Route::post('cards/prints', [CardPrintController::class, 'batch'])->name('cards.prints.batch');
            // PDFs are drawn by the PDF engine: heavier than other calls, so limited separately
            Route::post('cards/pdf', [CardPdfController::class, 'batch'])->middleware('throttle:20,1')->name('cards.pdf.batch');
            Route::get('cards/{card}', [CardController::class, 'show'])->name('cards.show');
            Route::patch('cards/{card}', [CardController::class, 'update'])->name('cards.update');
            Route::post('cards/{card}/revoke', [CardController::class, 'revoke'])->name('cards.revoke');
            Route::get('cards/{card}/prints', [CardPrintController::class, 'index'])->name('cards.prints.index');
            Route::get('cards/{card}/verifications', [CardVerificationController::class, 'history'])->name('cards.verifications.index');
            Route::post('cards/{card}/prints', [CardPrintController::class, 'store'])->name('cards.prints.store');
            Route::get('cards/{card}/pdf', [CardPdfController::class, 'show'])->middleware('throttle:60,1')->name('cards.pdf.show');
            Route::apiResource('cardholders.qualifications', QualificationController::class)->except('show')->scoped(['cardholder' => 'public_id', 'qualification' => 'public_id']);

            // Reports (Phase 5): the registry (columns of each report), then each report
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/card-prints', [CardPrintReportController::class, 'index'])->name('reports.card-prints');
        });

        // Platform administration
        Route::prefix('platform')->name('platform.')->middleware('platform.admin')->group(function () {
            Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'update']);
        });
    });
});
