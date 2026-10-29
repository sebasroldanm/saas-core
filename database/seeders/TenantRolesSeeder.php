<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permisos (derivados de los recursos del panel `app` por Shield) y roles base del tenant.
 *
 * Es idempotente: los permisos nuevos se crean siempre, pero los permisos de un rol base solo
 * se asignan cuando el rol se crea, para no pisar lo que el Administrador del tenant haya cambiado.
 */
class TenantRolesSeeder extends Seeder
{
    /**
     * Roles base y sus permisos. Administrador tiene acceso total vía Gate::before.
     *
     * @var array<string, array<string>>
     */
    public const BASE_ROLES = [
        User::ADMIN_ROLE => [],
        'Colaborador' => ['ViewAny:User', 'View:User', 'Create:User', 'Update:User'],
        'Validador' => ['ViewAny:User', 'View:User'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (static::permissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (static::BASE_ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string>
     */
    public static function permissionNames(): array
    {
        $previousPanel = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        try {
            return FilamentShield::getEntitiesPermissions();
        } finally {
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
