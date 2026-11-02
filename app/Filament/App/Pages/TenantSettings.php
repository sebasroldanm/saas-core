<?php

namespace App\Filament\App\Pages;

use App\Filament\Shared\Tenants\TenantSettingsFields;
use App\Models\Tenant;
use App\Support\Audit;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * "Configuración" del tenant: marca, región y contacto. Escribe en el modelo Tenant (BD central);
 * los archivos de marca quedan en el disco `public` del tenant. Permiso Shield: View:TenantSettings.
 */
class TenantSettings extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    protected static ?string $navigationLabel = 'Configuración';

    protected static ?string $title = 'Configuración';

    protected static ?string $slug = 'configuracion';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(TenantSettingsFields::snapshot($this->tenant()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TenantSettingsFields::branding(),
                TenantSettingsFields::region(),
                TenantSettingsFields::contact(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Guardar')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $tenant = $this->tenant();
        $before = TenantSettingsFields::snapshot($tenant);

        // `name` no se edita aquí: solo los campos del formulario
        $tenant->update(collect($this->form->getState())->only(array_diff(Audit::TENANT_SETTINGS, ['name']))->all());

        $tenant->deleteReplacedBrandingFiles($before);
        Audit::tenantSettingsChanged($tenant, $before, 'Configuración del tenant actualizada');

        Notification::make()->title('Configuración guardada')->success()->send();

        // Recarga para aplicar logo, colores y zona horaria en todo el panel
        $this->redirect(static::getUrl());
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
