<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant de core-saas. El id es el slug y define su BD: {prefijo}{slug} (p. ej. tenant_acme).
 *
 * @property string $id
 * @property string $name
 * @property TenantStatus $status
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $casts = [
        'status' => TenantStatus::class,
        'status_changed_at' => 'datetime',
    ];

    // El id (slug) se asigna a mano: no es autoincremental ni se genera
    public function getIncrementing()
    {
        return false;
    }

    public function shouldGenerateId(): bool
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    /**
     * Columnas reales de la tabla; el resto de atributos va a la columna JSON `data`.
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'status', 'status_changed_at', 'created_at', 'updated_at'];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function changeStatus(TenantStatus $status): void
    {
        $this->update(['status' => $status, 'status_changed_at' => now()]);
    }

    public static function databaseNameFor(string $slug): string
    {
        return config('tenancy.database.prefix').$slug.config('tenancy.database.suffix');
    }

    /**
     * Host del tenant: el primer dominio registrado. Si no tiene punto se trata como
     * subdominio del dominio central (acme => acme.localhost).
     */
    public function host(): ?string
    {
        $domain = $this->domains()->orderBy('id')->value('domain');

        if (! $domain) {
            return null;
        }

        return str_contains($domain, '.')
            ? $domain
            : $domain.'.'.config('tenancy.central_domains')[0];
    }

    /**
     * URL absoluta del tenant, reutilizando esquema y puerto de APP_URL.
     */
    public function url(string $path = '/'): ?string
    {
        $host = $this->host();

        if (! $host) {
            return null;
        }

        $appUrl = parse_url(config('app.url'));
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

        return ($appUrl['scheme'] ?? 'http').'://'.$host.$port.'/'.ltrim($path, '/');
    }
}
