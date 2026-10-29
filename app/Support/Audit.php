<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Etiquetas y registros de auditoría compartidos (spatie/laravel-activitylog).
 * El log se guarda en la BD del contexto actual: central o la del tenant.
 */
class Audit
{
    /** @var array<string, string> */
    public const LOGS = [
        'tenant' => 'Tenants',
        'user' => 'Usuarios',
        'customer' => 'Clientes',
        'role' => 'Roles',
        'auth' => 'Accesos',
    ];

    /** @var array<string, string> */
    public const EVENTS = [
        'created' => 'Creado',
        'updated' => 'Actualizado',
        'deleted' => 'Eliminado',
        'status_changed' => 'Cambio de estado',
        'destroyed' => 'Destruido',
        'login' => 'Inicio de sesión',
        'login_failed' => 'Login fallido',
        'logout' => 'Cierre de sesión',
        'impersonated' => 'Acceso desde panel central',
        'roles_changed' => 'Roles modificados',
        'permissions_changed' => 'Permisos modificados',
    ];

    /** @var array<string, string> */
    public const SUBJECTS = [
        Tenant::class => 'Tenant',
        Domain::class => 'Dominio',
        User::class => 'Usuario',
        Customer::class => 'Cliente',
        Role::class => 'Rol',
        Admin::class => 'Super admin',
    ];

    /**
     * Descripción de los eventos de modelo (LogsActivity): "Usuario creado", "Rol eliminado"...
     */
    public static function modelDescription(string $subject, string $event): string
    {
        return $subject.' '.match ($event) {
            'created' => 'creado',
            'updated' => 'actualizado',
            'deleted' => 'eliminado',
            default => $event,
        };
    }

    public static function eventLabel(?string $event): string
    {
        return static::EVENTS[$event] ?? (string) $event;
    }

    /**
     * Registra el cambio de roles de un usuario (Filament sincroniza la relación sin eventos de spatie).
     *
     * @param  array<string>  $before
     * @param  array<string>  $after
     */
    public static function rolesChanged(Model $user, array $before, array $after): void
    {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        activity('user')
            ->performedOn($user)
            ->event('roles_changed')
            ->withProperties([
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ])
            ->log('Roles del usuario modificados');
    }
}
