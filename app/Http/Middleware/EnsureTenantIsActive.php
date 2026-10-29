<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a tenants suspendidos o eliminados. Debe ir después del
 * middleware que inicializa la tenancy.
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        abort_if($tenant && ! $tenant->isActive(), 403, 'Este espacio no está disponible.');

        return $next($request);
    }
}
