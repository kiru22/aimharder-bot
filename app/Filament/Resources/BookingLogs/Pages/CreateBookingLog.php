<?php

namespace App\Filament\Resources\BookingLogs\Pages;

use App\Filament\Resources\BookingLogs\BookingLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBookingLog extends CreateRecord
{
    protected static string $resource = BookingLogResource::class;
}
