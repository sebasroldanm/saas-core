<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Filament\Shared\Activities\ActivityTable;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\TenantStats;
use App\Support\Timezones;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

/**
 * Ficha del tenant en el panel central (solo lectura).
 */
class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('General')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')->label('Nombre'),
                        TextEntry::make('id')->label('Slug')->copyable(),
                        TextEntry::make('status')->label('Estado')->badge(),
                        TextEntry::make('status_changed_at')->label('Último cambio de estado')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Acceso')
                    ->components([
                        TextEntry::make('access_domains')
                            ->label('Dominios')
                            ->state(fn (Tenant $record): HtmlString => static::domainsHtml($record)),
                        TextEntry::make('access_api')
                            ->label('API de clientes')
                            ->state(fn (Tenant $record): ?string => $record->url('api/v1'))
                            ->copyable()
                            ->placeholder('Sin dominio'),
                    ]),

                Section::make('Contacto')
                    ->columns(2)
                    ->components([
                        TextEntry::make('contact_email')->label('Email')->placeholder('—')->copyable(),
                        TextEntry::make('contact_phone')->label('Teléfono')->placeholder('—')->copyable(),
                    ]),

                Section::make('Región')
                    ->columns(2)
                    ->components([
                        TextEntry::make('timezone_label')
                            ->label('Zona horaria')
                            ->state(fn (Tenant $record): string => Timezones::label($record->effectiveTimezone()).($record->timezone ? '' : ' (por defecto)')),
                        TextEntry::make('local_time')
                            ->label('Hora local ahora')
                            ->state(fn (Tenant $record): string => now()->timezone($record->effectiveTimezone())->format('d/m/Y H:i')),
                    ]),

                Section::make('Marca')
                    ->description('La edita el Administrador del tenant en su panel (Configuración).')
                    ->columns(4)
                    ->components([
                        ColorEntry::make('effective_primary_color')
                            ->label('Color primario')
                            ->state(fn (Tenant $record): string => $record->effectivePrimaryColor())
                            ->copyable(),
                        ImageEntry::make('logo_url')->label('Logo')->state(fn (Tenant $record): ?string => $record->brandingUrl($record->logo_path))->imageHeight(40)->placeholder('Sin logo'),
                        ImageEntry::make('logo_dark_url')->label('Logo modo oscuro')->state(fn (Tenant $record): ?string => $record->brandingUrl($record->logo_dark_path))->imageHeight(40)->placeholder('—'),
                        ImageEntry::make('favicon_url')->label('Favicon')->state(fn (Tenant $record): ?string => $record->brandingUrl($record->favicon_path))->imageHeight(32)->placeholder('—'),
                    ]),

                Section::make('Uso')
                    ->columns(3)
                    ->components([
                        TextEntry::make('stats_database')
                            ->label('Base de datos')
                            ->state(fn (Tenant $record): string => ($stats = TenantStats::for($record))['database_exists']
                                ? "{$stats['database']} ({$stats['database_mb']} MB)"
                                : "{$stats['database']} (no existe)"),
                        TextEntry::make('stats_storage')->label('Archivos')->state(fn (Tenant $record): string => TenantStats::for($record)['storage_mb'].' MB'),
                        TextEntry::make('stats_last_login')
                            ->label('Último inicio de sesión')
                            ->state(fn (Tenant $record): mixed => TenantStats::for($record)['last_login_at'])
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Nunca'),
                        TextEntry::make('stats_users')->label('Usuarios del backoffice')->state(fn (Tenant $record): ?int => TenantStats::for($record)['users'])->placeholder('—'),
                        TextEntry::make('stats_customers')->label('Clientes')->state(fn (Tenant $record): ?int => TenantStats::for($record)['customers'])->placeholder('—'),
                    ]),

                Section::make('Auditoría reciente')
                    ->description('Últimos 10 eventos del panel central sobre este tenant. Lo que ocurre dentro del tenant está en su propia auditoría.')
                    ->columnSpanFull()
                    ->components([
                        TextEntry::make('recent_activity')
                            ->hiddenLabel()
                            ->state(fn (Tenant $record): HtmlString => static::activityHtml($record)),
                    ]),
            ]);
    }

    private static function domainsHtml(Tenant $record): HtmlString
    {
        $domains = $record->domains()->orderBy('id')->pluck('domain');

        if ($domains->isEmpty()) {
            return new HtmlString('<em>Sin dominios: el tenant no es accesible.</em>');
        }

        return new HtmlString($domains->map(function (string $domain, int $index): string {
            $url = Tenant::urlForHost(Tenant::hostFor($domain), 'app');
            $type = str_contains($domain, '.') ? 'dominio propio' : 'subdominio';
            $primary = $index === 0 ? ' · <strong>principal</strong>' : '';

            return '<a href="'.e($url).'" target="_blank" rel="noopener" class="underline">'.e($url).'</a> <span style="opacity:.7">('.$type.$primary.')</span>';
        })->implode('<br>'));
    }

    private static function activityHtml(Tenant $record): HtmlString
    {
        $items = Activity::query()
            ->where('subject_type', Tenant::class)
            ->where('subject_id', $record->getKey())
            ->with('causer')
            ->latest('id')
            ->limit(10)
            ->get();

        if ($items->isEmpty()) {
            return new HtmlString('<em>Sin eventos.</em>');
        }

        return new HtmlString($items->map(fn (Activity $activity): string => e($activity->created_at->format('d/m/Y H:i'))
            .' · <strong>'.e(Audit::eventLabel($activity->event)).'</strong> · '.e($activity->description)
            .' <span style="opacity:.7">('.e(ActivityTable::causerLabel($activity)).')</span>')->implode('<br>'));
    }
}
