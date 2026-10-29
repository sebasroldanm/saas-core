<?php

namespace App\Filament\App\Resources\Users\Pages;

use App\Filament\App\Resources\Users\UserResource;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        Audit::rolesChanged($this->getRecord(), [], $this->getRecord()->roles()->pluck('name')->all());
    }
}
