<?php

namespace App\Filament\Resources\BookingLogs\Pages;

use App\Filament\Resources\BookingLogs\BookingLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBookingLog extends EditRecord
{
    protected static string $resource = BookingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
