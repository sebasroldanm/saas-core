<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Datos públicos del tenant para las apps de los customers (antes del login): nombre, zona horaria
 * y marca. Las fechas de la API van en UTC; la app las convierte con `timezone`.
 */
class TenantController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return response()->json([
            'data' => [
                'name' => $tenant->name,
                'timezone' => $tenant->effectiveTimezone(),
                'branding' => [
                    'primary_color' => $tenant->effectivePrimaryColor(),
                    'logo_url' => $tenant->brandingUrl($tenant->logo_path),
                    'logo_dark_url' => $tenant->brandingUrl($tenant->logo_dark_path ?: $tenant->logo_path),
                    'favicon_url' => $tenant->brandingUrl($tenant->favicon_path),
                ],
            ],
        ]);
    }
}
