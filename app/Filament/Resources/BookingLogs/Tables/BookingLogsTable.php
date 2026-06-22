<?php

namespace App\Filament\Resources\BookingLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BookingLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('account.label')
                    ->searchable(),
                TextColumn::make('class_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('book_state')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('message')
                    ->limit(80),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => \App\Models\BookingLog::query()
                        ->distinct()
                        ->pluck('status', 'status')
                        ->toArray()),
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
