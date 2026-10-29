<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Corre contra MySQL (phpunit.xml): crea BD reales con prefijo tenant_test_.
 * Usa DatabaseMigrations y no RefreshDatabase porque CREATE DATABASE hace commit implícito.
 */
class TenantLifecycleTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_creates_tenant_with_database_admin_and_default_subdomain(): void
    {
        $this->createTenant('acme');

        $tenant = Tenant::findOrFail('acme');

        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame(['acme'], $tenant->domains->pluck('domain')->all());
        $this->assertTrue($tenant->database()->manager()->databaseExists('tenant_test_acme'));
        $this->assertSame('admin@acme.test', $tenant->run(fn () => User::sole()->email));
        // Los datos del admin no deben quedar en la columna JSON del tenant
        $this->assertArrayNotHasKey('admin_password', $tenant->getAttributes());
        $this->assertNull($tenant->getAttributes()['data'] ?? null);
    }

    public function test_tenant_can_have_subdomain_and_custom_domain(): void
    {
        $this->createTenant('beta', domains: ['beta', 'beta.test']);

        $this->get('http://beta.localhost/app/login')->assertOk();
        $this->backToCentral();
        $this->get('http://beta.test/app/login')->assertOk();
    }

    public function test_validates_tenant_form(): void
    {
        $this->createTenant('acme');

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Otro',
                'id' => 'acme',
                'domains' => [['domain' => 'localhost']],
                'admin_name' => 'Admin',
                'admin_email' => 'no-es-email',
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'otra',
            ])
            ->call('create')
            ->assertHasFormErrors(['id', 'domains.0.domain' => 'not_in', 'admin_email', 'admin_password']);

        Livewire::test(CreateTenant::class)
            ->fillForm(['id' => '1-Mal'])
            ->call('create')
            ->assertHasFormErrors(['id' => 'regex']);
    }

    public function test_panels_are_isolated_by_domain(): void
    {
        $this->createTenant('acme');

        $this->get('http://acme.localhost/app/login')->assertOk();
        $this->backToCentral();
        $this->get('http://localhost/app/login')->assertNotFound();
        $this->get('http://acme.localhost/admin/login')->assertNotFound();
    }

    public function test_impersonation_logs_into_tenant_panel(): void
    {
        $this->createTenant('acme');

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('impersonate')->table(Tenant::find('acme')))
            ->assertRedirectContains('acme.localhost')
            ->assertRedirectContains('/impersonate/');

        $token = ImpersonationToken::sole()->token;

        $this->get("http://acme.localhost/impersonate/{$token}")->assertRedirect('/app');
        $this->get('http://acme.localhost/app')->assertOk()->assertSee('Admin acme');
        // El token es de un solo uso
        $this->assertSame(0, ImpersonationToken::count());
    }

    public function test_suspended_and_deleted_tenants_are_blocked(): void
    {
        $this->createTenant('acme');
        $tenant = Tenant::find('acme');

        Livewire::test(ListTenants::class)->callAction(TestAction::make('suspend')->table($tenant));
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
        $this->get('http://acme.localhost/app/login')->assertForbidden();
        $this->backToCentral();

        Livewire::test(ListTenants::class)->callAction(TestAction::make('reactivate')->table($tenant));
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);

        Livewire::test(ListTenants::class)->callAction(TestAction::make('delete')->table($tenant));
        $this->assertSame(TenantStatus::Deleted, $tenant->refresh()->status);
        $this->assertTrue($tenant->database()->manager()->databaseExists('tenant_test_acme'));
        $this->get('http://acme.localhost/app/login')->assertForbidden();
        $this->backToCentral();

        Livewire::test(ListTenants::class)->callAction(TestAction::make('restore')->table($tenant));
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
    }

    public function test_destroy_requires_deleted_status_and_typed_slug(): void
    {
        $this->createTenant('acme');
        $tenant = Tenant::find('acme');

        Livewire::test(ListTenants::class)
            ->assertActionHidden(TestAction::make('destroy')->table($tenant));

        $tenant->changeStatus(TenantStatus::Deleted);

        $page = Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('destroy')->table($tenant))
            ->assertActionMounted('confirmDestroy');

        $page->fillForm(['slug' => 'otro'])
            ->callMountedAction()
            ->assertHasFormErrors(['slug']);
        $this->assertNotNull(Tenant::find('acme'));

        $page->fillForm(['slug' => 'acme'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertNull(Tenant::find('acme'));
        $this->assertFalse($tenant->database()->manager()->databaseExists('tenant_test_acme'));
    }
}
