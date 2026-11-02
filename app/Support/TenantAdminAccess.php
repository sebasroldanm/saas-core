<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordChangedByAdminNotification;
use App\Notifications\ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Restablecer el acceso de un usuario de un tenant desde el panel central (super admin).
 * Todo corre en la BD del tenant; la contraseña nunca se guarda en la BD central.
 */
class TenantAdminAccess
{
    public const MODE_LINK = 'link';

    public const MODE_MANUAL = 'manual';

    /**
     * Administradores del tenant para elegir a quién restablecer: [id => "Nombre <email>"].
     *
     * @return array<int, string>
     */
    public static function administrators(Tenant $tenant): array
    {
        return $tenant->run(fn (): array => User::role(User::ADMIN_ROLE)
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} <{$user->email}>"])
            ->all());
    }

    /**
     * Envía el enlace de restablecimiento al email del usuario (el mismo flujo que "¿Olvidaste tu
     * contraseña?"). Devuelve el estado del broker (Password::RESET_LINK_SENT, RESET_THROTTLED...).
     */
    public static function sendResetLink(Tenant $tenant, int $userId, ?Admin $by = null): string
    {
        return $tenant->run(function () use ($tenant, $userId, $by): string {
            $user = User::findOrFail($userId);

            // La URL firmada debe apuntar al dominio del tenant y al panel `app`, no al central
            $previousPanel = Filament::getCurrentPanel();
            Filament::setCurrentPanel(Filament::getPanel('app'));
            URL::forceRootUrl(rtrim($tenant->url('/'), '/'));

            try {
                $status = Password::broker('users')->sendResetLink(
                    ['email' => $user->email],
                    function (User $user, #[\SensitiveParameter] string $token): void {
                        $notification = new ResetPasswordNotification($token);
                        $notification->url = Filament::getResetPasswordUrl($token, $user);

                        $user->notify($notification);
                    },
                );
            } finally {
                URL::forceRootUrl(null);
                Filament::setCurrentPanel($previousPanel);
            }

            if ($status === Password::RESET_LINK_SENT) {
                static::audit($user, $by, self::MODE_LINK, 'Enlace de restablecimiento enviado desde el panel central');
            }

            return $status;
        });
    }

    /**
     * Define la contraseña directamente. Cierra las demás sesiones del usuario (AuthenticateSession
     * de Filament compara el hash) y le avisa por correo, sin incluir la contraseña.
     */
    public static function setPassword(Tenant $tenant, int $userId, #[\SensitiveParameter] string $password, ?Admin $by = null): void
    {
        $tenant->run(function () use ($userId, $password, $by): void {
            $user = User::findOrFail($userId);

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->notify(new PasswordChangedByAdminNotification);

            static::audit($user, $by, self::MODE_MANUAL, 'Contraseña definida desde el panel central');
        });
    }

    /**
     * Auditoría en la BD del tenant, a nombre del super admin (la central la registra la acción).
     */
    private static function audit(User $user, ?Admin $by, string $mode, string $description): void
    {
        activity('auth')
            ->causedBy($by)
            ->performedOn($user)
            ->event('admin_password_reset')
            ->withProperties(['mode' => $mode, 'ip' => request()->ip()])
            ->log($description);
    }
}
