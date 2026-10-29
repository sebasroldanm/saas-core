<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('core:make-superadmin {--name=} {--email=} {--password=}')]
#[Description('Crea o actualiza un super admin del panel central')]
class MakeSuperAdmin extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? text('Nombre', required: true),
            'email' => $this->option('email') ?? text('Email', required: true),
            'password' => $this->option('password') ?? password('Contraseña', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $admin = Admin::updateOrCreate(
            ['email' => $data['email']],
            ['name' => $data['name'], 'password' => $data['password']],
        );

        $this->components->info(($admin->wasRecentlyCreated ? 'Super admin creado: ' : 'Super admin actualizado: ').$admin->email);

        return self::SUCCESS;
    }
}
