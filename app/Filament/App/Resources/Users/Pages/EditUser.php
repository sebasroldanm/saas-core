<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string> Roles antes de guardar, para auditar el cambio */
    protected array $rolesBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->getRecord()->roles()->pluck('name')->all();
    }

    protected function afterSave(): void
    {
        Audit::rolesChanged($this->getRecord(), $this->rolesBefore, $this->getRecord()->roles()->pluck('name')->all());
    }
}
