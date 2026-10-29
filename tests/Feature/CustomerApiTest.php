<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->destroyTenants();

        parent::tearDown();
    }

    public function test_customer_logs_in_reads_profile_and_logs_out(): void
    {
        $this->createCustomer($this->createTenant('acme'));

        $login = $this->login('acme', 'cliente@acme.test', 'secreto123')
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('customer.email', 'cliente@acme.test')
            ->assertJsonMissingPath('customer.password');
        $token = $login->json('token');

        $this->api('get', 'acme', 'auth/me', $token)
            ->assertOk()
            ->assertJsonPath('data.email', 'cliente@acme.test');

        $this->api('post', 'acme', 'auth/logout', $token)->assertNoContent();
        $this->api('get', 'acme', 'auth/me', $token)->assertUnauthorized();
    }

    public function test_rejects_wrong_password_and_inactive_customer(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createCustomer($tenant);
        $this->createCustomer($tenant, ['email' => 'inactivo@acme.test', 'is_active' => false]);

        $this->login('acme', 'cliente@acme.test', 'otra-clave')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->login('acme', 'inactivo@acme.test', 'secreto123')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->login('acme', 'nadie@acme.test', 'secreto123')->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_token_only_works_on_its_own_tenant(): void
    {
        $this->createCustomer($this->createTenant('acme'));
        $this->createCustomer($this->createTenant('beta'), ['email' => 'cliente@beta.test']);

        // Ambos tenants tienen un token con id 1 en su propia BD: debe validar el hash, no solo el id
        $betaToken = $this->login('beta', 'cliente@beta.test', 'secreto123')->json('token');
        $token = $this->login('acme', 'cliente@acme.test', 'secreto123')->json('token');
        $this->assertSame(strtok($betaToken, '|'), strtok($token, '|'));

        $this->api('get', 'beta', 'auth/me', $token)->assertUnauthorized();
        $this->api('get', 'acme', 'auth/me', $token)->assertOk();
    }

    public function test_deactivating_or_changing_password_revokes_tokens(): void
    {
        $tenant = $this->createTenant('acme');
        $customer = $this->createCustomer($tenant);

        $token = $this->login('acme', 'cliente@acme.test', 'secreto123')->json('token');
        $tenant->run(fn () => Customer::findOrFail($customer->getKey())->update(['password' => 'nueva-clave-1']));
        $this->api('get', 'acme', 'auth/me', $token)->assertUnauthorized();

        $token = $this->login('acme', 'cliente@acme.test', 'nueva-clave-1')->json('token');
        $tenant->run(fn () => Customer::findOrFail($customer->getKey())->update(['is_active' => false]));
        $this->api('get', 'acme', 'auth/me', $token)->assertUnauthorized();
    }

    public function test_api_is_blocked_for_inactive_tenants_and_central_domain(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createCustomer($tenant);
        $tenant->changeStatus(TenantStatus::Suspended);

        $this->login('acme', 'cliente@acme.test', 'secreto123')->assertForbidden();
        $this->backToCentral();
        $this->postJson('http://localhost/api/v1/auth/login', ['email' => 'cliente@acme.test', 'password' => 'secreto123'])
            ->assertNotFound();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->createCustomer($this->createTenant('acme'));

        foreach (range(1, 5) as $attempt) {
            $this->login('acme', 'cliente@acme.test', 'mala')->assertUnprocessable();
        }

        $this->login('acme', 'cliente@acme.test', 'secreto123')->assertTooManyRequests();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createCustomer(Tenant $tenant, array $attributes = []): Customer
    {
        return $tenant->run(fn () => Customer::create([
            'name' => 'Cliente',
            'email' => 'cliente@acme.test',
            'password' => 'secreto123',
            ...$attributes,
        ]));
    }

    private function login(string $tenant, string $email, string $password): TestResponse
    {
        return $this->api('post', $tenant, 'auth/login', data: [
            'email' => $email,
            'password' => $password,
            'device_name' => 'tests',
        ]);
    }

    /**
     * Cada petición entra limpia: sin tenancy previa ni usuarios cacheados en los guards.
     *
     * @param  array<string, mixed>  $data
     */
    private function api(string $method, string $tenant, string $path, ?string $token = null, array $data = []): TestResponse
    {
        tenancy()->end();
        $this->app['auth']->forgetGuards();

        $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];

        return $this->json($method, "http://{$tenant}.localhost/api/v1/{$path}", $data, $headers);
    }
}
