<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\Actions\TenantActions;
use App\Filament\Admin\Resources\Tenants\Pages\Concerns\InteractsWithTenantDestruction;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Shared\Tenants\TenantSettingsFields;
use App\Support\Audit;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    use InteractsWithTenantDestruction;

    protected static string $resource = TenantResource::class;

    /** @var array<string, mixed> Valores antes de guardar, para auditar el cambio */
    protected array $settingsBefore = [];

    protected function beforeSave(): void
    {
        $this->settingsBefore = TenantSettingsFields::snapshot($this->getRecord());
    }

    protected function afterSave(): void
    {
        // Los dominios se auditan en App\Models\Domain
        Audit::tenantSettingsChanged($this->getRecord(), $this->settingsBefore, 'Tenant actualizado desde el panel central');
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            TenantActions::impersonate(),
            ActionGroup::make([
                TenantActions::open(),
                TenantActions::resetAdminPassword(),
                TenantActions::suspend(),
                TenantActions::reactivate(),
                TenantActions::delete(),
                TenantActions::restore(),
                TenantActions::destroy(),
            ]),
        ];
    }
}
