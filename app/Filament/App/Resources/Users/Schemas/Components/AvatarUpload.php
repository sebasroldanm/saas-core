<?php

namespace App\Filament\App\Resources\Users\Schemas\Components;

use Filament\Forms\Components\FileUpload;

/**
 * Campo de avatar del usuario, compartido por el formulario de usuarios y el perfil.
 * Guarda en el disco `public` del tenant (storage/tenant_{slug}/app/public/avatars).
 */
class AvatarUpload
{
    public static function make(): FileUpload
    {
        return FileUpload::make('avatar_path')
            ->label('Avatar')
            ->avatar()
            ->disk('public')
            ->directory('avatars')
            ->visibility('public')
            // Sin SVG: puede contener scripts
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(2048);
    }
}
