<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Activities\Pages\ListActivities as CentralActivities;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\App\Resources\Activities\Pages\ListActivities as TenantActivities;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_tenant_lifecycle_is_audited_in_central_database(): void
    {
        $tenant = $this->createTenant('acme', domains: ['acme', 'acme.test']);
        $admin = Admin::sole();

        $created = Activity::where('subject_type', Tenant::class)->where('event', 'created')->sole();
        $this->assertSame('acme', $created->subject_id);
        $this->assertTrue($created->causer->is($admin));
        $this->assertSame(['acme', 'acme.test'], $created->properties['domains']);
        $this->assertSame(2, Activity::where('subject_type', Domain::class)->where('event', 'created')->count());

        $tenant->changeStatus(TenantStatus::Suspended);
        $this->assertSame('Estado: Activo → Suspendido', Activity::where('event', 'status_changed')->sole()->description);

        $tenant->changeStatus(TenantStatus::Deleted);
        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('destroy')->table($tenant))
            ->fillForm(['slug' => 'acme'])
            ->callMountedAction();

        $destroyed = Activity::where('event', 'destroyed')->sole();
        $this->assertSame('tenant_test_acme', $destroyed->properties['database']);

        // Nada de esto llega a la auditoría de un tenant: vive en la BD central
        Livewire::test(CentralActivities::class)->assertOk()->assertCanSeeTableRecords([$created, $destroyed]);
    }

    public function test_impersonation_is_audited_in_both_databases_with_the_super_admin_as_causer(): void
    {
        $tenant = $this->createTenant('acme');

        Livewire::test(ListTenants::class)->callAction(TestAction::make('impersonate')->table($tenant));
        $central = Activity::where('event', 'impersonated')->sole();
        $this->assertTrue($central->causer->is(Admin::sole()));
        $this->assertArrayNotHasKey('token', $central->properties->all());

        $token = ImpersonationToken::sole()->token;
        $this->get("http://acme.localhost/impersonate/{$token}")->assertRedirect('/app');

        $inTenant = Activity::where('event', 'impersonated')->sole();
        $this->assertInstanceOf(Admin::class, $inTenant->causer);
        $this->assertSame('admin@acme.test', $inTenant->subject->email);
        // No se registra como un login normal del usuario suplantado
        $this->assertSame(0, Activity::where('event', 'login')->count());
    }

    public function test_tenant_actions_are_audited_in_tenant_database(): void
    {
        $tenant = $this->createTenant('acme');
        $admin = $this->actingInTenant($tenant);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ana',
                'email' => 'ana@acme.test',
                'password' => 'password123',
                'roles' => [Role::findByName('Validador')->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $ana = User::where('email', 'ana@acme.test')->sole();

        Livewire::test(EditUser::class, ['record' => $ana->getKey()])
            ->fillForm(['name' => 'Ana María', 'roles' => [Role::findByName('Colaborador')->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $userCreated = Activity::forSubject($ana)->where('event', 'created')->sole();
        $this->assertTrue($userCreated->causer->is($admin));
        $this->assertSame('Usuario creado', $userCreated->description);
        $this->assertArrayNotHasKey('password', $userCreated->attribute_changes['attributes']);

        $this->assertSame('Ana María', Activity::forSubject($ana)->where('event', 'updated')->sole()->attribute_changes['attributes']['name']);
        $rolesChanged = Activity::forSubject($ana)->where('event', 'roles_changed')->latest('id')->first();
        $this->assertSame(['Colaborador'], $rolesChanged->properties['added']);
        $this->assertSame(['Validador'], $rolesChanged->properties['removed']);

        Role::findByName('Validador')->syncPermissions(['ViewAny:User', 'View:User', 'ViewAny:Customer']);
        $this->assertSame(['ViewAny:Customer'], Activity::where('event', 'permissions_changed')->sole()->properties['added']);

        $customer = Customer::create(['name' => 'Cliente', 'email' => 'c@acme.test', 'password' => 'secreto123']);
        $customer->update(['is_active' => false]);
        $this->assertSame(['created', 'updated'], Activity::forSubject($customer)->orderBy('id')->pluck('event')->all());

        Livewire::test(TenantActivities::class)->assertOk()->assertCanSeeTableRecords([$userCreated]);
        $this->assertSame(0, Activity::on('mysql')->where('subject_type', User::class)->count(), 'Los logs del tenant no deben ir a la BD central');
    }

    public function test_logins_are_audited(): void
    {
        $tenant = $this->createTenant('acme');
        $this->actingInTenant($tenant);
        Auth::guard('web')->logout();

        Auth::guard('web')->attempt(['email' => 'admin@acme.test', 'password' => 'mala']);
        Auth::guard('web')->attempt(['email' => 'admin@acme.test', 'password' => 'password123']);

        $this->assertSame('admin@acme.test', Activity::where('event', 'login_failed')->sole()->properties['email']);
        $this->assertSame('web', Activity::where('event', 'login')->sole()->properties['guard']);
    }

    public function test_tenant_audit_requires_permission(): void
    {
        $tenant = $this->createTenant('acme');

        $this->actingInTenant($tenant, 'Validador');
        Livewire::test(TenantActivities::class)->assertForbidden();

        Role::findByName('Validador')->givePermissionTo('ViewAny:Activity');
        Livewire::test(TenantActivities::class)->assertOk();
    }

    public function test_logs_include_tenant_context(): void
    {
        $tenant = $this->createTenant('acme');
        $logFile = storage_path('logs/test-context.log');
        @unlink($logFile);
        config(['logging.channels.context_test' => ['driver' => 'single', 'path' => $logFile]]);

        tenancy()->initialize($tenant);
        $this->assertSame('acme', Context::get('tenant'));
        Log::channel('context_test')->error('fallo dentro del tenant');

        tenancy()->end();
        $this->assertNull(Context::get('tenant'));

        $this->assertStringContainsString('"tenant":"acme"', file_get_contents($logFile));
        unlink($logFile);
    }
}
