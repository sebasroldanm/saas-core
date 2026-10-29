<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\Actions\TenantActions;
use App\Filament\Admin\Resources\Tenants\Pages\Concerns\InteractsWithTenantDestruction;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    use InteractsWithTenantDestruction;

    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TenantActions::impersonate(),
            ActionGroup::make([
                TenantActions::open(),
                TenantActions::suspend(),
                TenantActions::reactivate(),
                TenantActions::delete(),
                TenantActions::restore(),
                TenantActions::destroy(),
            ]),
        ];
    }
}
