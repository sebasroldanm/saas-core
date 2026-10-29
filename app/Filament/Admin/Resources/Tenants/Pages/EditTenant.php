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

    protected ?string $nameBefore = null;

    protected function beforeSave(): void
    {
        $this->nameBefore = $this->getRecord()->name;
    }

    protected function afterSave(): void
    {
        // Los dominios se auditan en App\Models\Domain; aquí solo el nombre
        if ($this->nameBefore !== $this->getRecord()->name) {
            activity('tenant')
                ->performedOn($this->getRecord())
                ->event('updated')
                ->withChanges(['old' => ['name' => $this->nameBefore], 'attributes' => ['name' => $this->getRecord()->name]])
                ->log('Tenant actualizado');
        }
    }

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
