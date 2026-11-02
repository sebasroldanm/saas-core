<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Support\Facades\Storage;
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
 * @property ?string $contact_email
 * @property ?string $contact_phone
 * @property ?string $timezone
 * @property ?string $primary_color
 * @property ?string $logo_path
 * @property ?string $logo_dark_path
 * @property ?string $favicon_path
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    // Sin LogsActivity: stancl asocia eventos propios a created/deleted y Laravel deja de disparar
    // los eventos Eloquent que usa el trait. La auditoría del tenant se registra explícitamente.
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
        return [
            'id', 'name', 'status', 'status_changed_at', 'created_at', 'updated_at',
            'contact_email', 'contact_phone', 'timezone',
            'primary_color', 'logo_path', 'logo_dark_path', 'favicon_path',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function changeStatus(TenantStatus $status): void
    {
        $previous = $this->status;

        $this->update(['status' => $status, 'status_changed_at' => now()]);

        activity('tenant')
            ->performedOn($this)
            ->event('status_changed')
            ->withChanges(['old' => ['status' => $previous?->getLabel()], 'attributes' => ['status' => $status->getLabel()]])
            ->log("Estado: {$previous?->getLabel()} → {$status->getLabel()}");
    }

    public static function databaseNameFor(string $slug): string
    {
        return config('tenancy.database.prefix').$slug.config('tenancy.database.suffix');
    }

    /**
     * Host completo de un valor de la tabla `domains`: sin punto es un subdominio del dominio
     * central principal (acme => acme.localhost); con punto es un dominio propio (acme.test).
     */
    public static function hostFor(string $domain): string
    {
        return str_contains($domain, '.')
            ? $domain
            : $domain.'.'.config('tenancy.central_domains')[0];
    }

    /**
     * URL absoluta para un host, reutilizando esquema y puerto de APP_URL.
     */
    public static function urlForHost(string $host, string $path = '/'): string
    {
        $appUrl = parse_url(config('app.url'));
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';

        return ($appUrl['scheme'] ?? 'http').'://'.$host.$port.'/'.ltrim($path, '/');
    }

    /**
     * Host principal: el dominio registrado primero. Se usa en enlaces, correos y URLs de archivos.
     */
    public function host(): ?string
    {
        $domain = $this->domains()->orderBy('id')->value('domain');

        return $domain ? static::hostFor($domain) : null;
    }

    public function url(string $path = '/'): ?string
    {
        $host = $this->host();

        return $host ? static::urlForHost($host, $path) : null;
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?: config('core.tenant.default_timezone');
    }

    public function effectivePrimaryColor(): string
    {
        return $this->primary_color ?: config('core.tenant.default_primary_color');
    }

    /**
     * URL pública de un archivo de marca. Siempre por el dominio del tenant (/media), así funciona
     * también desde el panel central.
     */
    public function brandingUrl(?string $path): ?string
    {
        return $path ? $this->url('media/'.$path) : null;
    }

    /**
     * Archivos de marca reemplazados o quitados: se borran del disco `public` del tenant.
     * Debe llamarse con la tenancy del propio tenant inicializada.
     */
    public function deleteReplacedBrandingFiles(array $previous): void
    {
        foreach (['logo_path', 'logo_dark_path', 'favicon_path'] as $attribute) {
            $old = $previous[$attribute] ?? null;

            if ($old && $old !== $this->{$attribute}) {
                Storage::disk('public')->delete($old);
            }
        }
    }
}
