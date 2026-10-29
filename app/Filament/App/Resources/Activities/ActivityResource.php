<?php

namespace App\Filament\App\Resources\Activities;

use App\Filament\App\Resources\Activities\Pages\ListActivities;
use App\Filament\App\Resources\Activities\Pages\ViewActivity;
use App\Filament\Shared\Activities\ActivityInfolist;
use App\Filament\Shared\Activities\ActivityTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Auditoría (solo lectura) del activity_log de la BD del tenant.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'registro de auditoría';

    protected static ?string $pluralModelLabel = 'auditoría';

    protected static ?string $slug = 'auditoria';

    protected static string|UnitEnum|null $navigationGroup = 'Administración';

    public static function infolist(Schema $schema): Schema
    {
        return ActivityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActivityTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }
}
