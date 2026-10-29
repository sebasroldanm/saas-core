<?php

namespace Tests\Concerns;

use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Helpers para tests con tenants reales (una BD MySQL por tenant, prefijo tenant_test_).
 * Usar junto con DatabaseMigrations: CREATE DATABASE hace commit implícito.
 */
trait InteractsWithTenants
{
    protected function setUpInteractsWithTenants(): void
    {
        Filament::currentDomain('localhost');
        $this->actingAsSuperAdmin();
    }

    /**
     * Llamar desde tearDown() antes de parent::tearDown(): el rollback de DatabaseMigrations
     * se registra antes y borraría la tabla tenants si esto corriera como hook del trait.
     */
    protected function destroyTenants(): void
    {
        tenancy()->end();

        // Borra las BD de tenants creadas por el test
        Tenant::all()->each->delete();
    }

    protected function actingAsSuperAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs(Admin::firstOrCreate(
            ['email' => 'root@core-saas.test'],
            ['name' => 'Root', 'password' => 'password'],
        ), 'admin');
    }

    /**
     * Crea el tenant por el formulario real del panel central (BD, migraciones, roles y admin).
     *
     * @param  array<string>  $domains
     */
    protected function createTenant(string $slug, array $domains = []): Tenant
    {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => ucfirst($slug),
                'id' => $slug,
                'domains' => array_map(fn (string $domain): array => ['domain' => $domain], $domains),
                'admin_name' => "Admin {$slug}",
                'admin_email' => "admin@{$slug}.test",
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Tenant::findOrFail($slug);
    }

    /**
     * Entra al contexto del tenant y al panel `app` como el usuario dado.
     */
    protected function actingInTenant(Tenant $tenant, User|string|null $user = null): User
    {
        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $user = match (true) {
            $user instanceof User => $user,
            is_string($user) => User::create([
                'name' => "{$user} {$tenant->id}",
                'email' => strtolower($user).'@'.$tenant->id.'.test',
                'password' => 'password123',
            ])->assignRole($user),
            default => User::where('email', "admin@{$tenant->id}.test")->sole(),
        };

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Tras trabajar en un tenant, vuelve al contexto central (BD y panel admin).
     */
    protected function backToCentral(): void
    {
        tenancy()->end();
        $this->app['auth']->forgetGuards();
        $this->actingAsSuperAdmin();
    }
}
