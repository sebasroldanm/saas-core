<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Filament\Shared\Tenants\TenantSettingsFields;
use App\Models\Tenant;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Tenant')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create' && blank($get('id'))) {
                                    $set('id', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('id')
                            ->label('Slug')
                            ->helperText('Define la BD (tenant_{slug}) y el subdominio. No se puede cambiar después.')
                            ->required()
                            ->maxLength(40)
                            ->regex('/^[a-z][a-z0-9-]*$/')
                            ->validationMessages([
                                'regex' => 'Solo minúsculas, números y guiones; debe empezar con una letra.',
                            ])
                            ->unique(ignoreRecord: true)
                            ->rule(static fn (string $operation): Closure => function (string $attribute, mixed $value, Closure $fail) use ($operation): void {
                                // Evita chocar con una BD huérfana de un tenant destruido a medias
                                if ($operation === 'create' && (new Tenant(['id' => $value]))->database()->manager()->databaseExists(Tenant::databaseNameFor($value))) {
                                    $fail('Ya existe la base de datos '.Tenant::databaseNameFor($value).'.');
                                }
                            })
                            ->disabledOn('edit'),
                    ]),

                Section::make('Dominios')
                    ->description(new HtmlString(
                        'Por dónde entra el tenant. Dos formas, se pueden combinar:<br>'
                        .'• <strong>Subdominio</strong>: escribe solo el nombre, sin puntos. <code>acme</code> → <code>acme.'.e(config('tenancy.central_domains')[0]).'</code>. No requiere configuración extra.<br>'
                        .'• <strong>Dominio propio</strong>: el dominio completo, con puntos. <code>acme.test</code> o <code>app.cliente.com</code>. Debe apuntar a este servidor (en local, una línea en el archivo hosts; en producción, un registro DNS).<br>'
                        .'El primero de la lista es el <strong>principal</strong>: se usa en enlaces, correos y archivos. Si no cargas ninguno, se usa el slug como subdominio.'
                    ))
                    ->components([
                        Repeater::make('domains')
                            ->hiddenLabel()
                            ->relationship()
                            ->simple(
                                TextInput::make('domain')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn (?string $state): string => strtolower(trim((string) $state)))
                                    ->hint(fn (?string $state): ?string => filled($state) && ! static::isCentralSubdomain($state)
                                        ? '→ '.Tenant::urlForHost(Tenant::hostFor(strtolower(trim($state))), 'app')
                                        : null)
                                    ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/')
                                    ->validationMessages([
                                        'regex' => 'Dominio inválido: solo minúsculas, números, guiones y puntos.',
                                    ])
                                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        if (static::isCentralSubdomain((string) $value)) {
                                            $fail('Para un subdominio escribe solo el nombre, sin «.'.static::centralSuffix((string) $value).'».');
                                        }
                                    })
                                    ->notIn(config('tenancy.central_domains'))
                                    ->distinct()
                                    ->unique(ignoreRecord: true),
                            )
                            ->defaultItems(0)
                            ->addActionLabel('Agregar dominio'),
                    ]),

                TenantSettingsFields::contact(),

                TenantSettingsFields::region(),

                Section::make('Administrador del tenant')
                    ->description('Usuario con el que el tenant entra a su panel.')
                    ->columns(2)
                    ->visibleOn('create')
                    ->components([
                        TextInput::make('admin_name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('admin_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('admin_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->confirmed(),
                        TextInput::make('admin_password_confirmation')
                            ->label('Confirmar contraseña')
                            ->password()
                            ->revealable()
                            ->required(),
                    ]),
            ]);
    }

    /**
     * "acme.localhost" escrito como dominio propio: en realidad es un subdominio del central.
     */
    private static function isCentralSubdomain(string $value): bool
    {
        return static::centralSuffix($value) !== null;
    }

    private static function centralSuffix(string $value): ?string
    {
        $value = strtolower(trim($value));

        foreach (config('tenancy.central_domains') as $central) {
            if (str_ends_with($value, '.'.$central)) {
                return $central;
            }
        }

        return null;
    }
}
