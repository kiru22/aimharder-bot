<?php

namespace App\Filament\Resources\BookingRules\Pages;

use App\Filament\Resources\BookingRules\BookingRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBookingRule extends EditRecord
{
    protected static string $resource = BookingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
