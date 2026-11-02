<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\App\Pages\TenantSettings;
use App\Filament\App\Resources\Activities\Pages\ListActivities;
use App\Filament\App\Resources\Users\Pages\ViewUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\Colors\ColorManager;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_central_form_saves_contact_and_timezone_and_audits_changes(): void
    {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Acme',
                'id' => 'acme',
                'contact_email' => 'soporte@acme.test',
                'contact_phone' => '+57 300 000 0000',
                'timezone' => 'America/Bogota',
                'admin_name' => 'Admin',
                'admin_email' => 'admin@acme.test',
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::findOrFail('acme');
        $this->assertSame(['soporte@acme.test', '+57 300 000 0000', 'America/Bogota'], [$tenant->contact_email, $tenant->contact_phone, $tenant->timezone]);

        Livewire::test(EditTenant::class, ['record' => 'acme'])
            ->fillForm(['contact_phone' => '+57 311 111 1111', 'timezone' => 'America/Argentina/Buenos_Aires'])
            ->call('save')
            ->assertHasNoFormErrors();

        $changes = Activity::where('event', 'settings_updated')->sole()->attribute_changes;
        $this->assertEqualsCanonicalizing(['contact_phone' => '+57 311 111 1111', 'timezone' => 'America/Argentina/Buenos_Aires'], $changes['attributes']);
        $this->assertSame('America/Bogota', $changes['old']['timezone']);
    }

    public function test_rejects_invalid_timezone_and_central_subdomain_written_as_custom_domain(): void
    {
        $this->createTenant('acme');

        Livewire::test(EditTenant::class, ['record' => 'acme'])
            ->fillForm(['timezone' => 'GMT-5', 'domains' => [['domain' => 'otro.localhost']]])
            ->call('save')
            ->assertHasFormErrors(['timezone', 'domains.0.domain']);
    }

    public function test_dates_are_stored_in_utc_and_shown_in_tenant_timezone(): void
    {
        $tenant = $this->createTenant('acme');
        $tenant->update(['timezone' => 'America/Bogota']);

        $user = $this->actingInTenant($tenant->fresh());
        $this->assertSame('America/Bogota', FilamentTimezone::get());

        $user->forceFill(['created_at' => Carbon::parse('2026-01-01 12:00:00', 'UTC')])->saveQuietly();
        $this->assertSame('2026-01-01 12:00:00', User::query()->toBase()->where('id', $user->id)->value('created_at'));

        // 12:00 UTC = 07:00 en Bogotá (UTC-5)
        Livewire::test(ViewUser::class, ['record' => $user->getKey()])->assertSee('01/01/2026 07:00');

        $this->backToCentral();
        $this->assertSame('UTC', FilamentTimezone::get());
    }

    public function test_audit_date_filter_uses_tenant_days(): void
    {
        $tenant = $this->createTenant('acme');
        $tenant->update(['timezone' => 'America/Bogota']);
        $this->actingInTenant($tenant->fresh());

        // 03:00 UTC del 2 de enero = 22:00 del 1 de enero en Bogotá
        $late = activity('auth')->event('login')->log('Inicio tardío');
        $late->forceFill(['created_at' => Carbon::parse('2026-01-02 03:00:00', 'UTC')])->save();

        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['from' => '2026-01-01', 'until' => '2026-01-01'])
            ->assertCanSeeTableRecords([$late]);
        Livewire::test(ListActivities::class)
            ->filterTable('created_at', ['from' => '2026-01-02', 'until' => '2026-01-02'])
            ->assertCanNotSeeTableRecords([$late]);
    }

    public function test_tenant_admin_customizes_branding_and_panel_uses_it(): void
    {
        $tenant = $this->createTenant('acme');
        $admin = $this->actingInTenant($tenant);

        Livewire::test(TenantSettings::class)
            ->fillForm([
                'primary_color' => '#7c3aed',
                'logo_path' => UploadedFile::fake()->image('logo.png', 300, 80),
                'favicon_path' => UploadedFile::fake()->image('favicon.png', 64, 64),
                'timezone' => 'America/Bogota',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tenant->refresh();
        $this->assertSame('#7c3aed', $tenant->primary_color);
        $this->assertStringStartsWith('branding/', $tenant->logo_path);
        Storage::disk('public')->assertExists($tenant->logo_path);
        $this->assertStringContainsString('/storage/tenant_test_acme/app/public', Storage::disk('public')->path($tenant->logo_path));

        // Auditado en la BD del tenant, a nombre de su Administrador
        $audit = Activity::where('event', 'settings_updated')->sole();
        $this->assertTrue($audit->causer->is($admin));

        // El panel aplica logo, favicon y color (petición nueva: la tenancy se inicializa con los datos guardados)
        tenancy()->end();
        $html = $this->get('http://acme.localhost/app')->assertOk()->getContent();
        $this->assertStringContainsString($tenant->brandingUrl($tenant->logo_path), $html);
        $this->assertStringContainsString($tenant->brandingUrl($tenant->favicon_path), $html);

        // Color: FilamentColor lo resuelve al dibujar el panel. Se usa un ColorManager nuevo porque el
        // compartido cachea los colores del primer request del proceso de tests (en PHP-FPM cada request es nuevo)
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
        $manager = new ColorManager;
        foreach ((fn () => $this->colors)->call(app(ColorManager::class)) as $colors) {
            $manager->register($colors);
        }
        $this->assertSame(Color::hex('#7c3aed'), $manager->getColors()['primary']);

        // Reemplazar el logo borra el anterior
        $oldLogo = $tenant->logo_path;
        $this->actingInTenant($tenant, $admin);
        Livewire::test(TenantSettings::class)->fillForm(['logo_path' => null])->call('save');
        Storage::disk('public')->assertMissing($oldLogo);
    }

    public function test_branding_rejects_too_light_color_and_svg(): void
    {
        $this->actingInTenant($this->createTenant('acme'));

        Livewire::test(TenantSettings::class)
            ->fillForm(['primary_color' => '#ffffff', 'logo_path' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])
            ->call('save')
            ->assertHasFormErrors(['primary_color', 'logo_path']);
    }

    public function test_settings_page_requires_permission(): void
    {
        $tenant = $this->createTenant('acme');

        $this->actingInTenant($tenant, 'Validador');
        Livewire::test(TenantSettings::class)->assertForbidden();

        Role::findByName('Validador')->givePermissionTo('View:TenantSettings');
        Livewire::test(TenantSettings::class)->assertOk();
    }

    public function test_public_tenant_api_exposes_timezone_and_branding(): void
    {
        $tenant = $this->createTenant('acme');
        $tenant->update(['timezone' => 'America/Bogota', 'primary_color' => '#7c3aed']);
        $this->app['auth']->forgetGuards();

        $this->getJson('http://acme.localhost/api/v1/tenant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.timezone', 'America/Bogota')
            ->assertJsonPath('data.branding.primary_color', '#7c3aed')
            ->assertJsonPath('data.branding.logo_url', null)
            ->assertJsonMissingPath('data.contact_email');
    }

    public function test_detail_page_shows_access_contact_region_and_usage(): void
    {
        $tenant = $this->createTenant('acme', domains: ['acme', 'acme.test']);
        $tenant->update(['contact_email' => 'soporte@acme.test', 'timezone' => 'America/Bogota']);

        Livewire::test(ViewTenant::class, ['record' => 'acme'])
            ->assertOk()
            ->assertSee('http://acme.localhost')
            ->assertSee('http://acme.test')
            ->assertSee('principal')
            ->assertSee('soporte@acme.test')
            ->assertSee('America/Bogota')
            ->assertSee('tenant_test_acme')
            ->assertSee('Tenant creado');
    }
}
