<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\App\Pages\Auth\EditProfile;
use App\Filament\App\Resources\Users\Pages\EditUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_user_uploads_avatar_from_profile_into_tenant_storage(): void
    {
        $tenant = $this->createTenant('acme');
        $user = $this->actingInTenant($tenant);

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar_path' => UploadedFile::fake()->image('yo.jpg', 200, 200)])
            ->call('save')
            ->assertHasNoFormErrors();

        $path = $user->refresh()->avatar_path;
        $this->assertStringStartsWith('avatars/', $path);

        // Raíz del disco: storage/tenant_test_acme/app/public
        $this->assertStringContainsString('/storage/tenant_test_acme/app/public', Storage::disk('public')->path($path));
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('http://acme.localhost', $user->getFilamentAvatarUrl());
        $this->assertStringContainsString("/media/{$path}", $user->getFilamentAvatarUrl());
    }

    /**
     * Subida real por HTTP a la ruta de Livewire, con el usuario en sesión (como el navegador).
     * Esa ruta no inicializa la tenancy: no debe consultar `users` en la BD central.
     */
    public function test_livewire_upload_endpoint_works_for_logged_in_tenant_user(): void
    {
        $userId = $this->actingInTenant($this->createTenant('acme'))->getKey();
        tenancy()->end();
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']);

        // En tests Livewire guarda las subidas temporales en este disco
        Storage::fake('tmp-for-tests');

        // Livewire firma la URL de forma relativa (hasValidRelativeSignature)
        $url = 'http://acme.localhost'.URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);

        $this->withSession(['login_web_'.sha1(SessionGuard::class) => $userId])
            ->post($url, ['files' => [UploadedFile::fake()->image('yo.png', 50, 50)]])
            ->assertOk()
            ->assertJsonStructure(['paths']);
    }

    public function test_avatar_is_served_only_on_its_tenant_domain(): void
    {
        $acme = $this->createTenant('acme');
        $this->createTenant('beta');
        $path = $this->uploadAvatar($acme);
        $this->backToCentral();

        $this->get("http://acme.localhost/media/{$path}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->backToCentral();
        $this->get("http://beta.localhost/media/{$path}")->assertNotFound();
        $this->backToCentral();
        $this->get('http://acme.localhost/media/../../../.env')->assertNotFound();
        $this->backToCentral();
        $this->get("http://localhost/media/{$path}")->assertNotFound();
        $this->backToCentral();

        $acme->changeStatus(TenantStatus::Suspended);
        $this->get("http://acme.localhost/media/{$path}")->assertForbidden();
    }

    public function test_rejects_non_image_and_svg(): void
    {
        $user = $this->actingInTenant($this->createTenant('acme'));

        foreach ([UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'), UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')] as $file) {
            Livewire::test(EditUser::class, ['record' => $user->getKey()])
                ->fillForm(['avatar_path' => $file])
                ->call('save')
                ->assertHasFormErrors(['avatar_path']);
        }
    }

    public function test_replacing_avatar_deletes_previous_file_and_destroy_deletes_tenant_storage(): void
    {
        $tenant = $this->createTenant('acme');
        // Quitar el avatar (desde el perfil) borra el archivo
        $first = $this->uploadAvatar($tenant);
        $second = $this->uploadAvatar($tenant);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        // Reemplazarlo en un solo guardado también borra el anterior
        Storage::disk('public')->put('avatars/nuevo.png', 'x');
        auth()->user()->update(['avatar_path' => 'avatars/nuevo.png']);
        Storage::disk('public')->assertMissing($second);
        $tenantStorage = storage_path();
        $this->assertDirectoryExists($tenantStorage);

        $this->backToCentral();
        $tenant->changeStatus(TenantStatus::Deleted);
        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('destroy')->table($tenant))
            ->fillForm(['slug' => 'acme'])
            ->callMountedAction();

        $this->assertDirectoryDoesNotExist($tenantStorage);
    }

    private function uploadAvatar($tenant): string
    {
        $user = $this->actingInTenant($tenant);

        if ($user->avatar_path) {
            Livewire::test(EditProfile::class)->fillForm(['avatar_path' => null])->call('save')->assertHasNoFormErrors();
        }

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar_path' => UploadedFile::fake()->image('yo.png', 100, 100)])
            ->call('save')
            ->assertHasNoFormErrors();

        return $user->refresh()->avatar_path;
    }
}
