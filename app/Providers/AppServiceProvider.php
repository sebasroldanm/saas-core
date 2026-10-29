<?php

namespace App\Providers;

use App\Http\Controllers\ImpersonationController;
use App\Models\Admin;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
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

        // Activity es un modelo de vendor: su policy no se descubre sola
        Gate::policy(Activity::class, ActivityPolicy::class);

        $this->registerAuthAudit();

        // Subidas temporales de Livewire (config/livewire.php). Su ruta no inicializa la tenancy:
        // el throttle por defecto busca al usuario en sesión y lo consultaría en la BD central.
        // Por eso la clave es host + IP, sin tocar al usuario.
        RateLimiter::for('livewire-upload', fn (Request $request): Limit => Limit::perMinute(60)->by(
            $request->getHost().'|'.$request->ip()
        ));

        // Login de customers en la API: por tenant + email + IP
        RateLimiter::for('customer-login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            tenant('id').'|'.strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
    }

    /**
     * Inicios y cierres de sesión de los paneles (guards `web` y `admin`). Se guardan en la BD del
     * contexto: los del panel central en la central, los de un tenant en la del tenant.
     * El login de customers por la API se registra en CustomerAuthController.
     */
    private function registerAuthAudit(): void
    {
        // $event->user está tipado como Authenticatable; la auditoría necesita un modelo Eloquent
        Event::listen(function (Login $event): void {
            if (app()->bound('audit.impersonating') || ! $event->user instanceof Model) {
                return;
            }

            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('login')
                ->withProperties(['guard' => $event->guard, 'ip' => request()->ip()])
                ->log('Inicio de sesión');
        });

        Event::listen(function (Logout $event): void {
            if (! $event->user instanceof Model) {
                return;
            }

            // Cierre de un acceso desde el panel central: se atribuye al super admin
            $impersonation = request()->hasSession() ? session(ImpersonationController::SESSION_KEY) : null;

            if ($impersonation && $event->guard === 'web') {
                activity('auth')
                    ->causedBy($impersonation['admin_id'] ? Admin::find($impersonation['admin_id']) : null)
                    ->performedOn($event->user)
                    ->event('impersonation_ended')
                    ->withProperties(['ip' => request()->ip()])
                    ->log('Fin del acceso desde el panel central');

                return;
            }

            activity('auth')
                ->causedBy($event->user)
                ->performedOn($event->user)
                ->event('logout')
                ->withProperties(['guard' => $event->guard, 'ip' => request()->ip()])
                ->log('Cierre de sesión');
        });

        Event::listen(function (Failed $event): void {
            activity('auth')
                ->event('login_failed')
                ->withProperties([
                    'guard' => $event->guard,
                    'email' => $event->credentials['email'] ?? null,
                    'ip' => request()->ip(),
                ])
                ->log('Intento de inicio de sesión fallido');
        });
    }
}
