<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\TenantMediaController;
use App\Http\Middleware\EnsureTenantIsActive;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Rutas de cada tenant, identificado por dominio propio o subdominio.
| El panel Filament del tenant (/app) se registra en AppPanelProvider.
| La API de clientes (customers) vive en /api/v1 del mismo dominio.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomainOrSubdomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
])->group(function () {
    Route::redirect('/', '/app');

    // Acceso del super admin generado desde el panel central (token de un solo uso, 60 s)
    Route::get('/impersonate/{token}', ImpersonationController::class)->name('tenant.impersonate');
    Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])->name('tenant.impersonate.leave');
});

// Archivos públicos del tenant (avatares, etc.). Sin sesión ni CSRF: solo identifica el tenant
Route::middleware([
    InitializeTenancyByDomainOrSubdomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
])->get('/media/{path}', TenantMediaController::class)
    ->where('path', '.*')
    ->name('tenant.media');

Route::middleware([
    'api',
    InitializeTenancyByDomainOrSubdomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
])->prefix('api/v1')->name('tenant.api.')->group(function () {
    Route::post('auth/login', [CustomerAuthController::class, 'login'])
        ->middleware('throttle:customer-login')
        ->name('auth.login');

    Route::middleware('auth:customer')->group(function () {
        Route::get('auth/me', [CustomerAuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
    });
});
