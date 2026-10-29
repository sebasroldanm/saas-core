<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Borra la carpeta de archivos del tenant (storage/tenant_{slug}) al destruirlo.
 * stancl v3 solo borra la BD; sin esto quedarían avatares y archivos huérfanos.
 */
class DeleteTenantStorage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected TenantWithDatabase $tenant) {}

    public function handle(): void
    {
        // Se ejecuta en contexto central: storage_path() es la raíz sin sufijo de tenant
        $path = storage_path(config('tenancy.filesystem.suffix_base').$this->tenant->getTenantKey());

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }
}
