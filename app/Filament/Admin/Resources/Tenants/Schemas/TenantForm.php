<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Models\Tenant;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
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
                    ->description('Sin punto se usa como subdominio de localhost (acme → acme.localhost). Con punto es un dominio propio (acme.test) y debe apuntar al servidor (archivo hosts en local). Si no se carga ninguno, se usa el slug como subdominio.')
                    ->components([
                        Repeater::make('domains')
                            ->hiddenLabel()
                            ->relationship()
                            ->simple(
                                TextInput::make('domain')
                                    ->required()
                                    ->maxLength(255)
                                    ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/')
                                    ->validationMessages([
                                        'regex' => 'Dominio inválido: solo minúsculas, números, guiones y puntos.',
                                    ])
                                    ->notIn(config('tenancy.central_domains'))
                                    ->distinct()
                                    ->unique(ignoreRecord: true),
                            )
                            ->defaultItems(0)
                            ->addActionLabel('Agregar dominio'),
                    ]),

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
}
