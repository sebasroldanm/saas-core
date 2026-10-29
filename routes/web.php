<?php

use Illuminate\Support\Facades\Route;

// Rutas centrales: se limitan a los dominios centrales para no chocar con las rutas de tenant (routes/tenant.php)
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::redirect('/', '/admin');
    });
}
