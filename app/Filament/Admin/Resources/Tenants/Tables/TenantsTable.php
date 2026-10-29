<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\Actions\TenantActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('id')
                    ->label('Slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('domains.domain')
                    ->label('Dominios')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(TenantStatus::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record): bool => $record->status !== TenantStatus::Deleted),
                ActionGroup::make(TenantActions::all()),
            ]);
    }
}
