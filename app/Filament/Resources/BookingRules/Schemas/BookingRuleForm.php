<?php

namespace App\Filament\Resources\BookingRules\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BookingRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->relationship('account', 'label')
                    ->required(),
                CheckboxList::make('weekdays')
                    ->options([
                        1 => 'Lun',
                        2 => 'Mar',
                        3 => 'Mié',
                        4 => 'Jue',
                        5 => 'Vie',
                        6 => 'Sáb',
                        7 => 'Dom',
                    ])
                    ->columns(7)
                    ->required(),
                TextInput::make('time')
                    ->placeholder('18:00')
                    ->required(),
                TextInput::make('class_name')
                    ->placeholder('CrossFit')
                    ->required(),
                Toggle::make('insist')
                    ->helperText('Insistir / lista de espera'),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
