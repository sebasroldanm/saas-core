<?php

namespace App\Policies;

use App\Models\User;
use App\Support\RoleAssignment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Generada por Filament Shield y ajustada: además del permiso, editar o borrar un usuario
 * exige poder gestionar sus roles (ver RoleAssignment). El Administrador pasa por Gate::before.
 */
class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Update:User')
            && $authUser instanceof User
            && RoleAssignment::canManage($authUser, $user);
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Delete:User')
            && $authUser instanceof User
            && ! $authUser->is($user)
            && RoleAssignment::canManage($authUser, $user);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:User');
    }
}
