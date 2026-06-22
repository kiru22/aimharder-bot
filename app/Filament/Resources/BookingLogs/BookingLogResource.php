<?php

namespace App\Filament\Resources\BookingLogs;

use App\Filament\Resources\BookingLogs\Pages\ListBookingLogs;
use App\Filament\Resources\BookingLogs\Schemas\BookingLogForm;
use App\Filament\Resources\BookingLogs\Tables\BookingLogsTable;
use App\Models\BookingLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BookingLogResource extends Resource
{
    protected static ?string $model = BookingLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return BookingLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookingLogs::route('/'),
        ];
    }
}
