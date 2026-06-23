<?php

namespace App\Filament\Widgets;

use App\Models\BookingLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentBookingsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Reservas recientes')
            ->query(BookingLog::query()->latest('created_at')->limit(10))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('account.label')
                    ->label('Cuenta'),
                TextColumn::make('class_id')
                    ->label('Clase ID'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'booked'   => 'success',
                        'failed'   => 'danger',
                        'no_match' => 'warning',
                        'already'  => 'info',
                        'skipped'  => 'gray',
                        default    => 'gray',
                    }),
                TextColumn::make('message')
                    ->label('Mensaje')
                    ->limit(60),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
