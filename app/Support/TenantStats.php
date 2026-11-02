<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

/**
 * Datos de uso de un tenant para su ficha en el panel central. Se calculan al abrir la ficha
 * (consultas a la BD del tenant y a disco): no usar en listados.
 */
class TenantStats
{
    /**
     * @return array{database: string, database_exists: bool, database_mb: ?float, users: ?int, customers: ?int, storage_mb: float, last_login_at: ?CarbonInterface}
     */
    public static function for(Tenant $tenant): array
    {
        return once(function () use ($tenant): array {
            $database = Tenant::databaseNameFor($tenant->id);
            $exists = $tenant->database()->manager()->databaseExists($database);

            $usage = $exists ? $tenant->run(fn (): array => [
                'users' => User::count(),
                'customers' => Customer::count(),
                'last_login_at' => Activity::where('event', 'login')->latest('id')->value('created_at'),
            ]) : ['users' => null, 'customers' => null, 'last_login_at' => null];

            return [
                'database' => $database,
                'database_exists' => $exists,
                'database_mb' => $exists ? static::databaseMegabytes($database) : null,
                'storage_mb' => static::storageMegabytes($tenant),
                ...$usage,
            ];
        });
    }

    private static function databaseMegabytes(string $database): float
    {
        $bytes = DB::connection(config('tenancy.database.central_connection'))
            ->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->sum(DB::raw('DATA_LENGTH + INDEX_LENGTH'));

        return round($bytes / 1048576, 2);
    }

    private static function storageMegabytes(Tenant $tenant): float
    {
        // Contexto central: storage_path() sin sufijo de tenant
        $path = storage_path(config('tenancy.filesystem.suffix_base').$tenant->getTenantKey());

        if (! File::isDirectory($path)) {
            return 0.0;
        }

        $bytes = collect(File::allFiles($path))->sum(fn (\SplFileInfo $file): int => $file->getSize());

        return round($bytes / 1048576, 2);
    }
}
