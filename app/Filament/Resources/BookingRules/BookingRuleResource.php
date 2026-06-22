<?php

namespace App\Filament\Resources\BookingRules;

use App\Filament\Resources\BookingRules\Pages\CreateBookingRule;
use App\Filament\Resources\BookingRules\Pages\EditBookingRule;
use App\Filament\Resources\BookingRules\Pages\ListBookingRules;
use App\Filament\Resources\BookingRules\Schemas\BookingRuleForm;
use App\Filament\Resources\BookingRules\Tables\BookingRulesTable;
use App\Models\BookingRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BookingRuleResource extends Resource
{
    protected static ?string $model = BookingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return BookingRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingRulesTable::configure($table);
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
            'index' => ListBookingRules::route('/'),
            'create' => CreateBookingRule::route('/create'),
            'edit' => EditBookingRule::route('/{record}/edit'),
        ];
    }
}
