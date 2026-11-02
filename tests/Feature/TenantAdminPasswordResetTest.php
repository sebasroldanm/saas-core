<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordChangedByAdminNotification;
use App\Notifications\ResetPasswordNotification;
use App\Support\TenantAdminAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantAdminPasswordResetTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_super_admin_sends_reset_link_that_points_to_tenant_domain(): void
    {
        $tenant = $this->createTenant('acme');
        $adminId = $this->adminIdOf($tenant);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), ['user_id' => $adminId, 'mode' => TenantAdminAccess::MODE_LINK])
            ->assertHasNoActionErrors()
            ->assertNotified('Enlace enviado');

        $notification = $this->sentResetNotification($tenant);
        $this->assertStringStartsWith('http://acme.localhost', $notification->url);
        $this->assertStringContainsString('/app/password-reset/reset', $notification->url);
        $this->assertStringContainsString('signature=', $notification->url);
        $this->assertSame('Acme', $notification->tenantName);
        $this->assertSame('Restablecer tu contraseña de Acme', $notification->toMail(User::make(['name' => 'X']))->subject);

        // Auditoría en ambas BD; la central sin datos sensibles
        $central = Activity::where('event', 'admin_password_reset')->sole();
        $this->assertSame('admin@acme.test', $central->properties['user_email']);
        $this->assertEqualsCanonicalizing(['user_email', 'mode'], array_keys($central->properties->all()));
        tenancy()->initialize($tenant);
        $this->assertInstanceOf(Admin::class, Activity::where('event', 'admin_password_reset')->sole()->causer);
    }

    public function test_link_works_on_tenant_domain_and_lets_the_admin_choose_a_password(): void
    {
        $tenant = $this->createTenant('acme');
        TenantAdminAccess::sendResetLink($tenant, $this->adminIdOf($tenant));
        $notification = $this->sentResetNotification($tenant);

        $this->get($notification->url)->assertOk();

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(ResetPassword::class, ['email' => 'admin@acme.test', 'token' => $notification->token])
            ->set('password', 'NuevaClave123')
            ->set('passwordConfirmation', 'NuevaClave123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('NuevaClave123', User::where('email', 'admin@acme.test')->sole()->password));
        $this->assertSame(1, Activity::where('event', 'password_reset')->count());
    }

    public function test_reset_link_is_throttled(): void
    {
        $tenant = $this->createTenant('acme');
        $adminId = $this->adminIdOf($tenant);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), ['user_id' => $adminId, 'mode' => TenantAdminAccess::MODE_LINK]);
        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), ['user_id' => $adminId, 'mode' => TenantAdminAccess::MODE_LINK])
            ->assertNotified('No se envió el enlace');

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_super_admin_sets_password_manually(): void
    {
        $tenant = $this->createTenant('acme');
        $adminId = $this->adminIdOf($tenant);
        $oldToken = $tenant->run(fn () => User::find($adminId)->remember_token);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), [
                'user_id' => $adminId,
                'mode' => TenantAdminAccess::MODE_MANUAL,
                'password' => 'OtraClave123',
                'password_confirmation' => 'OtraClave123',
            ])
            ->assertHasNoActionErrors();

        tenancy()->initialize($tenant);
        $user = User::find($adminId);
        $this->assertTrue(Hash::check('OtraClave123', $user->password));
        $this->assertNotSame($oldToken, $user->remember_token);
        Notification::assertSentTo($user, PasswordChangedByAdminNotification::class);
        Notification::assertNotSentTo($user, ResetPasswordNotification::class);
        $this->assertSame('manual', Activity::where('event', 'admin_password_reset')->sole()->properties['mode']);
    }

    public function test_manual_mode_requires_confirmed_password(): void
    {
        $tenant = $this->createTenant('acme');

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), [
                'user_id' => $this->adminIdOf($tenant),
                'mode' => TenantAdminAccess::MODE_MANUAL,
                'password' => 'OtraClave123',
                'password_confirmation' => 'distinta',
            ])
            ->assertHasActionErrors(['password']);
    }

    public function test_only_tenant_administrators_can_be_reset(): void
    {
        $tenant = $this->createTenant('acme');
        $validadorId = $this->actingInTenant($tenant, 'Validador')->getKey();
        $this->backToCentral();

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('resetAdminPassword')->table($tenant), ['user_id' => $validadorId, 'mode' => TenantAdminAccess::MODE_LINK])
            // El select solo acepta administradores (además, la acción aborta con 403 si se saltea)
            ->assertHasActionErrors(['user_id']);

        Notification::assertNothingSent();
        $this->assertSame(0, Activity::where('event', 'admin_password_reset')->count());
    }

    public function test_tenant_users_can_request_reset_from_tenant_login(): void
    {
        $tenant = $this->createTenant('acme');
        $this->app['auth']->forgetGuards();

        $this->get('http://acme.localhost/app/password-reset/request')->assertOk();

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@acme.test'])
            ->call('request')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'admin@acme.test')->sole();
        Notification::assertSentTo($user, ResetPasswordNotification::class, fn ($n) => $n->tenantName === 'Acme');
    }

    private function adminIdOf(Tenant $tenant): int
    {
        return $tenant->run(fn (): int => User::role(User::ADMIN_ROLE)->value('id'));
    }

    private function sentResetNotification(Tenant $tenant): ResetPasswordNotification
    {
        $user = $tenant->run(fn () => User::where('email', 'admin@acme.test')->sole());

        $sent = Notification::sent($user, ResetPasswordNotification::class);
        $this->assertCount(1, $sent);

        return $sent->first();
    }
}
