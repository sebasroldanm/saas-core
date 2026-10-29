<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Users\Pages\ListUsers;
use App\Filament\App\Resources\Users\Pages\ViewUser;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use Database\Seeders\TenantRolesSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantPermissionsTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_new_tenant_gets_base_roles_and_admin_role(): void
    {
        $tenant = $this->createTenant('acme');

        $admin = $this->actingInTenant($tenant);

        $this->assertTrue($admin->isAdministrator());
        $this->assertEqualsCanonicalizing(['Administrador', 'Colaborador', 'Validador'], Role::pluck('name')->all());
        $this->assertEqualsCanonicalizing(TenantRolesSeeder::permissionNames(), Permission::pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['ViewAny:User', 'View:User', 'Create:User', 'Update:User'],
            Role::findByName('Colaborador')->permissions->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['ViewAny:User', 'View:User'],
            Role::findByName('Validador')->permissions->pluck('name')->all(),
        );
    }

    public function test_administrador_has_full_access_but_cannot_alter_its_own_role(): void
    {
        $admin = $this->actingInTenant($this->createTenant('acme'));

        Livewire::test(ListCustomers::class)->assertOk();
        Livewire::test(ListRoles::class)->assertOk();
        Livewire::test(CreateRole::class)->assertOk();

        $adminRole = Role::findByName(User::ADMIN_ROLE);
        $this->assertFalse($admin->can('update', $adminRole));
        $this->assertFalse($admin->can('delete', $adminRole));
        $this->assertTrue($admin->can('update', Role::findByName('Colaborador')));
    }

    public function test_validador_can_only_list_and_view_users(): void
    {
        $validador = $this->actingInTenant($this->createTenant('acme'), 'Validador');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertActionVisible(TestAction::make('view')->table($validador))
            ->assertActionHidden(TestAction::make('edit')->table($validador))
            ->assertActionHidden(TestAction::make('delete')->table($validador));
        Livewire::test(ViewUser::class, ['record' => $validador->getKey()])->assertOk();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(ListCustomers::class)->assertForbidden();
        Livewire::test(ListRoles::class)->assertForbidden();
    }

    public function test_colaborador_creates_users_without_escalating_privileges(): void
    {
        $tenant = $this->createTenant('acme');
        $admin = $this->actingInTenant($tenant);
        $colaborador = $this->actingInTenant($tenant, 'Colaborador');

        // Puede asignar roles con permisos que ya tiene (Validador, Colaborador)...
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuevo',
                'email' => 'nuevo@acme.test',
                'password' => 'password123',
                'roles' => [Role::findByName('Validador')->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(User::where('email', 'nuevo@acme.test')->sole()->hasRole('Validador'));

        // ...pero no Administrador
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Escalada',
                'email' => 'escalada@acme.test',
                'password' => 'password123',
                'roles' => [Role::findByName(User::ADMIN_ROLE)->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles']);
        $this->assertNull(User::where('email', 'escalada@acme.test')->first());

        // No puede editar ni borrar a un Administrador, ni borrar usuarios en general
        Livewire::test(EditUser::class, ['record' => $admin->getKey()])->assertForbidden();
        $this->assertFalse($colaborador->can('delete', User::where('email', 'nuevo@acme.test')->sole()));
    }

    public function test_custom_roles_created_by_tenant_admin_apply(): void
    {
        $tenant = $this->createTenant('acme');
        $this->actingInTenant($tenant);

        $soporte = Role::create(['name' => 'Soporte', 'guard_name' => 'web']);
        $soporte->givePermissionTo(['ViewAny:Customer', 'View:Customer']);

        $this->actingInTenant($tenant, 'Soporte');

        Livewire::test(ListCustomers::class)->assertOk();
        Livewire::test(ListUsers::class)->assertForbidden();
    }

    public function test_permission_cache_is_isolated_per_tenant(): void
    {
        $acme = $this->createTenant('acme');
        $beta = $this->createTenant('beta');

        // Calienta la cache de permisos de acme
        $acmeValidador = $this->actingInTenant($acme, 'Validador');
        $this->assertFalse($acmeValidador->can('Create:User'));
        $this->backToCentral();

        // En beta el Validador puede crear usuarios
        $this->actingInTenant($beta);
        Role::findByName('Validador')->givePermissionTo('Create:User');
        $betaValidador = $this->actingInTenant($beta, 'Validador');
        $this->assertTrue($betaValidador->can('Create:User'));
        $this->backToCentral();

        // ...y eso no se filtra a acme
        tenancy()->initialize($acme);
        $this->assertFalse(User::findOrFail($acmeValidador->getKey())->can('Create:User'));
    }
}
