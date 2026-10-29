<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Reglas para asignar roles sin escalar privilegios: un usuario solo puede asignar roles
 * cuyos permisos ya tiene, y solo puede gestionar usuarios cuyos roles podría asignar.
 */
class RoleAssignment
{
    /**
     * @return Collection<int, Role>
     */
    public static function assignableRoles(User $actor): Collection
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        if ($actor->isAdministrator()) {
            return $roles;
        }

        $ownPermissions = $actor->getAllPermissions()->pluck('name');

        return $roles
            ->reject(fn (Role $role): bool => $role->name === User::ADMIN_ROLE)
            ->filter(fn (Role $role): bool => $role->permissions->pluck('name')->diff($ownPermissions)->isEmpty())
            ->values();
    }

    public static function canManage(User $actor, User $target): bool
    {
        if ($actor->isAdministrator()) {
            return true;
        }

        return $target->roles->pluck('id')
            ->diff(static::assignableRoles($actor)->pluck('id'))
            ->isEmpty();
    }
}
