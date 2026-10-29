<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * "Entrar como admin" desde el panel central (stancl UserImpersonation).
 *
 * Al entrar busca el registro que dejó el panel central para ese token (qué super admin y a dónde
 * volver), lo audita en el tenant y lo guarda en la sesión: con eso se muestra la barra de
 * impersonación (AppPanelProvider). Al salir cierra la sesión del tenant y vuelve al panel central,
 * cuya sesión (otra cookie, otro dominio) sigue abierta.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonation';

    public function __invoke(string $token): RedirectResponse
    {
        $central = Activity::on(config('tenancy.database.central_connection'))
            ->where('event', 'impersonated')
            ->where('properties->token_hash', hash('sha256', $token))
            ->latest('id')
            ->first();
        $admin = $central?->causer;

        // Evita que el listener de Login lo registre como un inicio de sesión del propio usuario
        app()->instance('audit.impersonating', true);

        $response = UserImpersonation::makeResponse($token);

        activity('auth')
            ->causedBy($admin instanceof Admin ? $admin : null)
            ->performedOn(auth('web')->user())
            ->event('impersonated')
            ->withProperties(['ip' => request()->ip()])
            ->log('Acceso desde el panel central');

        session()->put(self::SESSION_KEY, [
            'admin_id' => $admin?->getKey(),
            'admin_name' => $admin?->name ?? 'Super admin',
            'return_url' => $central?->properties['return_url'] ?? rtrim(config('app.url'), '/').'/admin',
        ]);

        return $response;
    }

    /**
     * Botón "Volver al panel central" de la barra. La auditoría la registra el listener de Logout.
     */
    public function leave(): RedirectResponse
    {
        $impersonation = session(self::SESSION_KEY);

        abort_unless($impersonation, 403);

        auth('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->away($impersonation['return_url']);
    }
}
