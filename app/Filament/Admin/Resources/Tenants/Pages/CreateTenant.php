<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    // CREATE DATABASE hace commit implícito en MySQL: no se puede envolver en una transacción
    protected ?bool $hasDatabaseTransactions = false;

    protected function handleRecordCreation(array $data): Model
    {
        $admin = Arr::only($data, ['admin_name', 'admin_email', 'admin_password']);

        // Al crearse, stancl crea la BD del tenant y corre migrations/tenant (TenancyServiceProvider)
        $tenant = Tenant::create([
            ...Arr::except($data, ['admin_name', 'admin_email', 'admin_password', 'admin_password_confirmation']),
            'status' => TenantStatus::Active,
            'status_changed_at' => now(),
        ]);

        try {
            // Los roles base ya existen: los crea TenantDatabaseSeeder en el pipeline de creación
            $tenant->run(function () use ($admin): void {
                $user = User::create([
                    'name' => $admin['admin_name'],
                    'email' => $admin['admin_email'],
                    'password' => $admin['admin_password'],
                ])->assignRole(User::ADMIN_ROLE);

                Audit::rolesChanged($user, [], [User::ADMIN_ROLE]);
            });
        } catch (Throwable $e) {
            // Sin admin el tenant queda inutilizable: se deshace todo, incluida su BD
            $tenant->delete();

            throw $e;
        }

        return $tenant;
    }

    protected function afterCreate(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        if (! $tenant->domains()->exists()) {
            $tenant->domains()->create(['domain' => $tenant->id]);
        }

        activity('tenant')
            ->performedOn($tenant)
            ->event('created')
            ->withProperties([
                'name' => $tenant->name,
                'database' => Tenant::databaseNameFor($tenant->id),
                'domains' => $tenant->domains()->pluck('domain')->all(),
            ])
            ->log('Tenant creado');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
