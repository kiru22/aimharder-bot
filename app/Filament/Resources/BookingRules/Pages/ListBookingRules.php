<?php

namespace App\Filament\Resources\BookingRules\Pages;

use App\Filament\Resources\BookingRules\BookingRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookingRules extends ListRecords
{
    protected static string $resource = BookingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
