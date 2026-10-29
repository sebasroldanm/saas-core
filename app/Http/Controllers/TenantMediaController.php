<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve los archivos del disco `public` del tenant actual (storage/tenant_{slug}/app/public).
 * Como la raíz del disco es la del tenant identificado por el dominio, un tenant nunca puede
 * leer archivos de otro.
 */
class TenantMediaController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $root = realpath(Storage::disk('public')->path(''));
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;

        // Mismo 404 para inexistente y para intentos de salir de la raíz (../)
        abort_if($file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        return response()->file($file, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
