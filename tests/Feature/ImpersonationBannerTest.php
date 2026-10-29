<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Models\Admin;
use App\Models\Tenant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ImpersonationBannerTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_banner_is_shown_while_impersonating_and_leave_returns_to_central_panel(): void
    {
        $tenant = $this->createTenant('acme');
        $this->impersonate($tenant);

        $this->get('http://acme.localhost/app')
            ->assertOk()
            ->assertSee('ingresaste desde el panel central como')
            ->assertSee('Root')
            ->assertSee('Volver al panel central');

        $this->post('http://acme.localhost/impersonate/leave')
            ->assertRedirectContains('/admin/tenants/acme/edit');

        // La sesión del tenant quedó cerrada
        $this->assertFalse(Auth::guard('web')->check());
        $this->get('http://acme.localhost/app')->assertRedirect();

        $ended = Activity::where('event', 'impersonation_ended')->sole();
        $this->assertInstanceOf(Admin::class, $ended->causer);
        $this->assertSame('admin@acme.test', $ended->subject->email);
        $this->assertSame(0, Activity::where('event', 'logout')->count());
    }

    public function test_banner_is_not_shown_on_a_normal_login_and_leave_is_forbidden(): void
    {
        $tenant = $this->createTenant('acme');
        $user = $this->actingInTenant($tenant);
        tenancy()->end();

        $this->actingAs($user, 'web')
            ->get('http://acme.localhost/app')
            ->assertOk()
            ->assertDontSee('Volver al panel central');

        tenancy()->end();
        $this->post('http://acme.localhost/impersonate/leave')->assertForbidden();
    }

    public function test_filament_logout_while_impersonating_is_audited_as_impersonation_end(): void
    {
        $tenant = $this->createTenant('acme');
        $this->impersonate($tenant);

        $this->post('http://acme.localhost/app/logout')->assertRedirect();

        $this->assertSame(1, Activity::where('event', 'impersonation_ended')->count());
        $this->assertSame(0, Activity::where('event', 'logout')->count());
    }

    private function impersonate(Tenant $tenant): void
    {
        Livewire::test(ListTenants::class)->callAction(TestAction::make('impersonate')->table($tenant));

        $token = ImpersonationToken::sole()->token;
        $this->app['auth']->forgetGuards();

        $this->get("http://{$tenant->id}.localhost/impersonate/{$token}")->assertRedirect('/app');
        tenancy()->end();
    }
}
