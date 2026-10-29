<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder de la BD de cada tenant. Lo ejecuta stancl al crear el tenant (config tenancy.seeder_parameters).
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantRolesSeeder::class);
    }
}
