<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al usuario de que un super admin de la plataforma le definió una contraseña nueva.
 * No incluye la contraseña: se la comunica quien la definió por otro canal.
 */
class PasswordChangedByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public ?string $tenantName;

    public function __construct()
    {
        $this->tenantName = tenant('name');
    }

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $space = $this->tenantName ?? config('app.name');

        return (new MailMessage)
            ->subject("Tu contraseña de {$space} fue cambiada")
            ->greeting("Hola {$notifiable->name}:")
            ->line("El equipo de soporte de la plataforma definió una contraseña nueva para tu cuenta en {$space}.")
            ->line('Las sesiones abiertas con la contraseña anterior se cerrarán.')
            ->line('Si no solicitaste este cambio, contacta a soporte de inmediato.')
            ->salutation('— '.$space);
    }
}
