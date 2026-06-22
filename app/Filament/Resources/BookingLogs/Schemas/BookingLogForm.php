<?php

namespace App\Filament\Resources\BookingLogs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class BookingLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->relationship('account', 'id')
                    ->required(),
                TextInput::make('booking_rule_id')
                    ->numeric(),
                DatePicker::make('target_date')
                    ->required(),
                TextInput::make('class_id'),
                TextInput::make('status')
                    ->required(),
                TextInput::make('book_state')
                    ->numeric(),
                Textarea::make('message')
                    ->columnSpanFull(),
            ]);
    }
}
