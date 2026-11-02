<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\Tenants\Actions\TenantActions;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                TextColumn::make('contact_email')
                    ->label('Contacto')
                    ->description(fn ($record): ?string => $record->contact_phone)
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('timezone')
                    ->label('Zona horaria')
                    ->placeholder('Por defecto')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->recordUrl(fn ($record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn ($record): bool => $record->status !== TenantStatus::Deleted),
                ActionGroup::make(TenantActions::all()),
            ]);
    }
}
