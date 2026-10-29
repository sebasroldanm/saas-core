<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente de un tenant (BD del tenant). No entra al panel: se autentica por la API con tokens.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password'])]
class Customer extends Authenticatable
{
    use HasApiTokens;

    protected static function booted(): void
    {
        // Desactivar o cambiar la contraseña invalida las sesiones abiertas en la API
        static::updated(function (Customer $customer): void {
            if ($customer->wasChanged('password') || ($customer->wasChanged('is_active') && ! $customer->is_active)) {
                $customer->tokens()->delete();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
