<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario del backoffice de un tenant (panel /app). Usa la conexión por defecto, que con
 * la tenancy inicializada apunta a la BD del tenant (tenant_{slug}). Sus permisos son roles de spatie.
 * Los clientes del tenant son otro modelo: App\Models\Customer.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Rol con acceso total dentro del tenant (bypass en AppServiceProvider).
     */
    public const ADMIN_ROLE = 'Administrador';

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app' && tenancy()->initialized;
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(self::ADMIN_ROLE);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
