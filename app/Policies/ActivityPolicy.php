<?php

namespace App\Policies;

use App\Models\Admin;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Activitylog\Models\Activity;

/**
 * Auditoría (solo lectura). En el tenant depende de los permisos de Shield (ViewAny/View:Activity);
 * los super admins del panel central (sin roles) ven siempre la auditoría central.
 * Registrada en AppServiceProvider: Activity es un modelo de vendor.
 */
class ActivityPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser instanceof Admin || $authUser->can('ViewAny:Activity');
    }

    public function view(AuthUser $authUser, Activity $activity): bool
    {
        return $authUser instanceof Admin || $authUser->can('View:Activity');
    }
}
