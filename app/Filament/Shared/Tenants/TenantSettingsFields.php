<?php

namespace App\Filament\Shared\Tenants;

use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Timezones;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * Campos de configuración del tenant compartidos por el panel central (TenantForm) y por la página
 * "Configuración" del panel del tenant.
 */
class TenantSettingsFields
{
    public static function contact(): Section
    {
        return Section::make('Contacto')
            ->description('Responsable del tenant para soporte y avisos de la plataforma.')
            ->columns(2)
            ->components([
                TextInput::make('contact_email')
                    ->label('Email de contacto')
                    ->email()
                    ->maxLength(255),
                TextInput::make('contact_phone')
                    ->label('Teléfono de contacto')
                    ->tel()
                    ->maxLength(30),
            ]);
    }

    public static function region(): Section
    {
        return Section::make('Región')
            ->description('Las fechas se guardan en UTC y se muestran en esta zona horaria en todo el panel del tenant.')
            ->components([
                Select::make('timezone')
                    ->label('Zona horaria')
                    ->options(Timezones::grouped())
                    ->searchable()
                    ->placeholder('Por defecto: '.config('core.tenant.default_timezone'))
                    ->helperText(fn (?string $state): string => 'Hora actual: '.now()->timezone($state ?: config('core.tenant.default_timezone'))->format('d/m/Y H:i'))
                    ->live()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && ! Timezones::isValid($value)) {
                            $fail('Zona horaria inválida.');
                        }
                    }),
            ]);
    }

    /**
     * Solo en el panel del tenant: los archivos se guardan en su disco `public` (storage/tenant_{slug}).
     */
    public static function branding(): Section
    {
        return Section::make('Marca')
            ->description('Se aplica al panel y al login del tenant, y la API la expone en /api/v1/tenant.')
            ->columns(2)
            ->components([
                ColorPicker::make('primary_color')
                    ->label('Color primario')
                    ->hex()
                    ->placeholder(config('core.tenant.default_primary_color'))
                    ->helperText('Botones, enlaces y elementos activos. Evita colores muy claros.')
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && static::contrastWithWhite($value) < 1.5) {
                            $fail('Color demasiado claro: el texto blanco de los botones no se leería.');
                        }
                    }),
                static::image('favicon_path', 'Favicon', 'Cuadrado, PNG o ICO, hasta 256 KB.', ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'], 256),
                static::image('logo_path', 'Logo', 'Reemplaza al nombre en la barra superior y el login. PNG, JPG o WebP, hasta 1 MB.'),
                static::image('logo_dark_path', 'Logo para modo oscuro', 'Opcional. Si no se carga, se usa el logo normal.'),
            ]);
    }

    /**
     * Relación de contraste WCAG del color contra blanco (1 = igual, 21 = máximo).
     */
    public static function contrastWithWhite(string $hex): float
    {
        [$r, $g, $b] = array_map(
            fn (string $channel): float => ($c = hexdec($channel) / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            str_split(ltrim($hex, '#'), 2),
        );

        return 1.05 / (0.2126 * $r + 0.7152 * $g + 0.0722 * $b + 0.05);
    }

    /**
     * @param  array<string>  $types
     */
    private static function image(string $name, string $label, string $help, array $types = ['image/png', 'image/jpeg', 'image/webp'], int $maxKb = 1024): FileUpload
    {
        // Sin SVG: servido desde el dominio del tenant podría ejecutar scripts
        return FileUpload::make($name)
            ->label($label)
            ->helperText($help)
            ->image()
            ->disk('public')
            ->directory('branding')
            ->visibility('public')
            ->acceptedFileTypes($types)
            ->maxSize($maxKb);
    }

    /**
     * Valores actuales de los atributos editables, para auditar el cambio.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Tenant $tenant): array
    {
        return $tenant->only(Audit::TENANT_SETTINGS);
    }
}
