<?php

namespace App\Filament\Shared\Activities;

use App\Support\Audit;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('Fecha')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('log_name')
                            ->label('Módulo')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => Audit::LOGS[$state] ?? (string) $state),
                        TextEntry::make('event')
                            ->label('Evento')
                            ->formatStateUsing(fn (?string $state): string => Audit::eventLabel($state)),
                        TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),
                        TextEntry::make('subject_label')
                            ->label('Sobre')
                            ->state(fn (Activity $record): string => ActivityTable::subjectLabel($record)),
                        TextEntry::make('causer_label')
                            ->label('Realizado por')
                            ->state(fn (Activity $record): string => ActivityTable::causerLabel($record)),
                    ]),
                Section::make('Cambios')
                    ->columns(2)
                    ->visible(fn (Activity $record): bool => filled($record->attribute_changes))
                    ->components([
                        KeyValueEntry::make('old_values')
                            ->label('Antes')
                            ->keyLabel('Campo')
                            ->valueLabel('Valor')
                            ->state(fn (Activity $record): array => static::flatten($record->attribute_changes?->get('old'))),
                        KeyValueEntry::make('new_values')
                            ->label('Después')
                            ->keyLabel('Campo')
                            ->valueLabel('Valor')
                            ->state(fn (Activity $record): array => static::flatten($record->attribute_changes?->get('attributes'))),
                    ]),
                Section::make('Detalle')
                    ->visible(fn (Activity $record): bool => filled($record->properties))
                    ->components([
                        KeyValueEntry::make('properties_flat')
                            ->hiddenLabel()
                            ->keyLabel('Dato')
                            ->valueLabel('Valor')
                            ->state(fn (Activity $record): array => static::flatten($record->properties?->except('token_hash')->all())),
                    ]),
            ]);
    }

    /**
     * KeyValueEntry solo muestra escalares: las listas (permisos agregados, etc.) se unen con comas.
     *
     * @return array<string, string>
     */
    private static function flatten(mixed $values): array
    {
        return collect($values ?? [])
            ->map(fn (mixed $value): string => match (true) {
                is_array($value) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value)) ?: '—',
                is_bool($value) => $value ? 'sí' : 'no',
                $value === null => '—',
                $value instanceof \BackedEnum => (string) $value->value,
                default => (string) $value,
            })
            ->all();
    }
}
