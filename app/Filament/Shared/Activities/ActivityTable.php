<?php

namespace App\Filament\Shared\Activities;

use App\Support\Audit;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Tabla de auditoría compartida por el panel central y el de cada tenant. Cada panel lee el
 * activity_log de su propia BD.
 */
class ActivityTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('log_name')
                    ->label('Módulo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Audit::LOGS[$state] ?? (string) $state),
                TextColumn::make('event')
                    ->label('Evento')
                    ->formatStateUsing(fn (?string $state): string => Audit::eventLabel($state)),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject_label')
                    ->label('Sobre')
                    ->state(fn (Activity $record): string => static::subjectLabel($record)),
                TextColumn::make('causer_label')
                    ->label('Realizado por')
                    ->state(fn (Activity $record): string => static::causerLabel($record)),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Módulo')
                    ->options(Audit::LOGS),
                SelectFilter::make('event')
                    ->label('Evento')
                    ->options(Audit::EVENTS),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function subjectLabel(Activity $record): string
    {
        if (! $record->subject_type) {
            return '—';
        }

        $type = Audit::SUBJECTS[$record->subject_type] ?? class_basename($record->subject_type);
        $name = $record->subject?->name ?? $record->subject?->domain ?? null;

        return $name ? "{$type}: {$name}" : "{$type} #{$record->subject_id}";
    }

    public static function causerLabel(Activity $record): string
    {
        if (! $record->causer_type) {
            return 'Sistema / anónimo';
        }

        $type = Audit::SUBJECTS[$record->causer_type] ?? class_basename($record->causer_type);
        $name = $record->causer?->name ?? "#{$record->causer_id}";

        return "{$name} ({$type})";
    }
}
