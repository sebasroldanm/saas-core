<?php

namespace App\Filament\App\Resources\Users\Schemas;

use App\Models\User;
use App\Support\RoleAssignment;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                // En edición es opcional: si se deja vacía se conserva la actual
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                // Solo se ofrecen los roles que el usuario actual puede asignar (sin escalar privilegios)
                Select::make('roles')
                    ->label('Roles')
                    ->relationship(
                        'roles',
                        'name',
                        fn (Builder $query): Builder => $query->whereKey(static::assignableRoleIds()),
                    )
                    ->multiple()
                    ->preload()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (collect($value)->map(fn ($id): int => (int) $id)->diff(static::assignableRoleIds())->isNotEmpty()) {
                            $fail('No puede asignar uno de los roles seleccionados.');
                        }
                    })
                    // Nadie cambia sus propios roles (evita quitarse el Administrador por error)
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
            ]);
    }

    /**
     * @return array<int>
     */
    private static function assignableRoleIds(): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        return RoleAssignment::assignableRoles($actor)->pluck('id')->all();
    }
}
