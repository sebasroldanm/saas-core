<?php

namespace App\Notifications;

use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Correo de restablecimiento de contraseña de los usuarios del tenant, en español y con el nombre
 * del tenant. Reemplaza al de Filament (binding en AppServiceProvider), así que lo usan tanto
 * "¿Olvidaste tu contraseña?" del panel como el envío desde el panel central. Se envía en cola.
 */
class ResetPasswordNotification extends FilamentResetPassword
{
    public ?string $tenantName;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);

        // Se guarda al crearla: el nombre viaja con el job aunque cambie el contexto
        $this->tenantName = tenant('name');
    }

    public function toMail($notifiable): MailMessage
    {
        $space = $this->tenantName ?? config('app.name');

        return (new MailMessage)
            ->subject("Restablecer tu contraseña de {$space}")
            ->greeting("Hola {$notifiable->name}:")
            ->line("Recibimos una solicitud para restablecer la contraseña de tu cuenta en {$space}.")
            ->action('Elegir una nueva contraseña', $this->url)
            ->line('El enlace vence en '.config('auth.passwords.users.expire').' minutos y solo puede usarse una vez.')
            ->line('Si no lo pediste, ignora este correo: tu contraseña actual sigue funcionando.')
            ->salutation('— '.$space);
    }
}
