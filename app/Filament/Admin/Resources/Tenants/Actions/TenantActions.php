<?php

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Acciones de ciclo de vida del tenant, compartidas por la tabla y la página de edición.
 */
class TenantActions
{
    /**
     * @return array<Action>
     */
    public static function all(): array
    {
        return [
            static::impersonate(),
            static::open(),
            static::suspend(),
            static::reactivate(),
            static::delete(),
            static::restore(),
            static::destroy(),
        ];
    }

    /**
     * Entra al panel del tenant como el Administrador más antiguo (normalmente el creado con el tenant).
     */
    public static function impersonate(): Action
    {
        return Action::make('impersonate')
            ->label('Entrar como admin')
            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
            ->color('primary')
            ->visible(fn (Tenant $record): bool => $record->isActive())
            ->action(function (Tenant $record, Action $action): void {
                $userId = $record->run(fn () => User::role(User::ADMIN_ROLE)->orderBy('id')->value('id'));

                if (! $userId) {
                    Notification::make()->title('El tenant no tiene usuarios con rol Administrador.')->danger()->send();
                    $action->halt();
                }

                $token = tenancy()->impersonate($record, (string) $userId, '/app', 'web');

                // El tenant busca este registro por el hash del token para saber qué super admin entró
                activity('auth')
                    ->performedOn($record)
                    ->event('impersonated')
                    ->withProperties([
                        'user_id' => $userId,
                        'token_hash' => hash('sha256', $token->token),
                        // A dónde vuelve el botón "Volver al panel central" de la barra del tenant
                        'return_url' => TenantResource::getUrl('edit', ['record' => $record]),
                    ])
                    ->log('Ingreso al panel del tenant como administrador');

                $action->redirect($record->url('impersonate/'.$token->token));
            });
    }

    public static function open(): Action
    {
        return Action::make('open')
            ->label('Abrir login')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (Tenant $record): bool => $record->isActive())
            ->url(fn (Tenant $record): ?string => $record->url('app'), shouldOpenInNewTab: true);
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Suspender')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Active)
            ->requiresConfirmation()
            ->modalHeading('¿Suspender este tenant?')
            ->modalDescription('Sus usuarios no podrán entrar hasta que se reactive. Los datos se conservan.')
            ->action(function (Tenant $record): void {
                $record->changeStatus(TenantStatus::Suspended);
                Notification::make()->title('Tenant suspendido')->success()->send();
            });
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivar')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Suspended)
            ->requiresConfirmation()
            ->action(function (Tenant $record): void {
                $record->changeStatus(TenantStatus::Active);
                Notification::make()->title('Tenant reactivado')->success()->send();
            });
    }

    /**
     * Eliminación reversible: bloquea el acceso y conserva la BD.
     */
    public static function delete(): Action
    {
        return Action::make('delete')
            ->label('Eliminar')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Deleted)
            ->requiresConfirmation()
            ->modalHeading('¿Seguro desea eliminar?')
            ->modalDescription('El tenant quedará sin acceso. Sus datos se conservan hasta que se destruya.')
            ->modalSubmitActionLabel('Sí')
            ->modalCancelActionLabel('No')
            ->action(function (Tenant $record): void {
                $record->changeStatus(TenantStatus::Deleted);
                Notification::make()->title('Tenant eliminado')->body('Puede restaurarlo o destruirlo definitivamente.')->success()->send();
            });
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Restaurar')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Deleted)
            ->requiresConfirmation()
            ->modalHeading('¿Restaurar este tenant?')
            ->action(function (Tenant $record): void {
                $record->changeStatus(TenantStatus::Active);
                Notification::make()->title('Tenant restaurado')->success()->send();
            });
    }

    /**
     * Primer paso de la destrucción: confirma la intención y abre el paso del slug
     * (acción confirmDestroy de la página, ver InteractsWithTenantDestruction).
     */
    public static function destroy(): Action
    {
        return Action::make('destroy')
            ->label('Destruir')
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Deleted)
            ->requiresConfirmation()
            ->modalHeading('¿Desea destruir toda la data de este tenant?')
            ->modalDescription('Se borrará su base de datos, usuarios y dominios. No se puede deshacer.')
            ->modalSubmitActionLabel('Sí')
            ->modalCancelActionLabel('No')
            ->action(function (Tenant $record, Action $action): void {
                $action->getLivewire()->replaceMountedAction('confirmDestroy', ['tenant' => $record->getKey()]);
            });
    }
}
