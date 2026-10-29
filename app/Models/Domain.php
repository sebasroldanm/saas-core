<?php

namespace App\Models;

use App\Support\Audit;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * Dominio o subdominio de un tenant (BD central). Se audita porque define por dónde entra el tenant.
 */
class Domain extends BaseDomain
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('tenant')
            ->logOnly(['domain', 'tenant_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => Audit::modelDescription('Dominio', $event));
    }
}
