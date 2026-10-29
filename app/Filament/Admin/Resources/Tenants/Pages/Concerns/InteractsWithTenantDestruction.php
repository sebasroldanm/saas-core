<?php

namespace App\Filament\Admin\Resources\Tenants\Pages\Concerns;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Segundo paso de "Destruir": pide escribir el slug y borra el tenant y su BD.
 * Se monta desde TenantActions::destroy() con replaceMountedAction().
 */
trait InteractsWithTenantDestruction
{
    public function confirmDestroyAction(): Action
    {
        return Action::make('confirmDestroy')
            ->modalHeading('Confirmar destrucción')
            ->modalDescription(fn (array $arguments): string => 'Escriba el slug «'.$arguments['tenant'].'» para destruir el tenant y su base de datos '.Tenant::databaseNameFor($arguments['tenant']).'.')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('danger')
            ->color('danger')
            ->schema(fn (array $arguments): array => [
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->in([$arguments['tenant']])
                    ->validationMessages(['in' => 'El slug no coincide.']),
            ])
            ->modalSubmitActionLabel('Destruir definitivamente')
            ->action(function (array $arguments): void {
                $tenant = Tenant::findOrFail($arguments['tenant']);

                // Solo se destruye lo que antes pasó por "Eliminar"
                abort_unless($tenant->status === TenantStatus::Deleted, 403);

                activity('tenant')
                    ->performedOn($tenant)
                    ->event('destroyed')
                    ->withProperties([
                        'name' => $tenant->name,
                        'database' => Tenant::databaseNameFor($tenant->id),
                        'domains' => $tenant->domains()->pluck('domain')->all(),
                    ])
                    ->log('Tenant destruido: base de datos y archivos eliminados');

                // stancl borra la BD (y DeleteTenantStorage sus archivos) en el evento TenantDeleted;
                // dominios y tokens caen en cascada
                $tenant->delete();

                Notification::make()->title('Tenant destruido')->success()->send();

                $this->redirect(TenantResource::getUrl('index'));
            });
    }
}
