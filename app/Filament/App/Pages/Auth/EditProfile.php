<?php

namespace App\Filament\App\Pages\Auth;

use App\Filament\App\Resources\Users\Schemas\Components\AvatarUpload;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Schema;

/**
 * Perfil del usuario del tenant: cada usuario edita sus datos y su avatar sin necesitar permisos sobre Usuarios.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                AvatarUpload::make(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }
}
