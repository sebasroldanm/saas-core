<?php

namespace App\Filament\App\Resources\Users\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('avatar_path')
                    ->label('Avatar')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn ($record): string => filament()->getUserAvatarUrl($record)),
                TextEntry::make('name')
                    ->label('Nombre'),
                TextEntry::make('email'),
                TextEntry::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('Sin roles'),
                TextEntry::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i'),
            ]);
    }
}
