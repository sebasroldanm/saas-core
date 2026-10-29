<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Audit;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario del backoffice de un tenant (panel /app). Usa la conexión por defecto, que con
 * la tenancy inicializada apunta a la BD del tenant (tenant_{slug}). Sus permisos son roles de spatie.
 * Los clientes del tenant son otro modelo: App\Models\Customer.
 */
#[Fillable(['name', 'email', 'password', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

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
     * El avatar vive en el disco `public` del tenant y se sirve por /media (routes/tenant.php).
     */
    public function getFilamentAvatarUrl(): ?string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        return $this->avatar_path ? $disk->url($this->avatar_path) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'avatar_path'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => Audit::modelDescription('Usuario', $event));
    }

    protected static function booted(): void
    {
        // Al reemplazar o quitar el avatar, o borrar el usuario, se elimina el archivo anterior
        static::updated(function (User $user): void {
            if ($user->wasChanged('avatar_path') && ($old = $user->getOriginal('avatar_path'))) {
                Storage::disk('public')->delete($old);
            }
        });

        static::deleted(function (User $user): void {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
