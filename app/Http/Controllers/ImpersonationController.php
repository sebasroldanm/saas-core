<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * Consume el token de "Entrar como admin" (stancl) y deja constancia en la auditoría del tenant
 * de qué super admin entró, buscando el registro que dejó el panel central para ese token.
 */
class ImpersonationController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        $admin = Activity::on(config('tenancy.database.central_connection'))
            ->where('event', 'impersonated')
            ->where('properties->token_hash', hash('sha256', $token))
            ->latest('id')
            ->first()
            ?->causer;

        // Evita que el listener de Login lo registre como un inicio de sesión del propio usuario
        app()->instance('audit.impersonating', true);

        $response = UserImpersonation::makeResponse($token);

        activity('auth')
            ->causedBy($admin instanceof Admin ? $admin : null)
            ->performedOn(auth('web')->user())
            ->event('impersonated')
            ->withProperties(['ip' => request()->ip()])
            ->log('Acceso desde el panel central');

        return $response;
    }
}
