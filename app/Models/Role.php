<?php

namespace App\Models;

use App\Support\Audit;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol de un tenant (BD del tenant). Extiende el de spatie para auditar altas, cambios y
 * cambios de permisos (Shield guarda los permisos con syncPermissions()).
 */
class Role extends SpatieRole
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('role')
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => Audit::modelDescription('Rol', $event));
    }

    public function syncPermissions(...$permissions): static
    {
        $before = $this->permissions()->pluck('name')->sort()->values();

        parent::syncPermissions(...$permissions);

        $after = $this->permissions()->pluck('name')->sort()->values();

        if ($before->all() !== $after->all()) {
            activity('role')
                ->performedOn($this)
                ->event('permissions_changed')
                ->withProperties([
                    'added' => $after->diff($before)->values()->all(),
                    'removed' => $before->diff($after)->values()->all(),
                ])
                ->log('Permisos del rol modificados');
        }

        return $this;
    }
}
