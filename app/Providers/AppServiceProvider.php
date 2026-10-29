<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El rol Administrador del tenant tiene todos los permisos. Solo aplica a usuarios
        // de tenant: los super admins centrales (App\Models\Admin) no usan roles.
        Gate::before(function (object $user, string $ability, array $arguments): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            // El rol Administrador no se edita ni se borra (ni siquiera un Administrador):
            // evita que un tenant se quede sin acceso total
            $target = $arguments[0] ?? null;
            if ($target instanceof Role && $target->name === User::ADMIN_ROLE && in_array($ability, ['update', 'delete'], true)) {
                return false;
            }

            return $user->isAdministrator() ? true : null;
        });

        // Login de customers en la API: por tenant + email + IP
        RateLimiter::for('customer-login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            tenant('id').'|'.strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
    }
}
