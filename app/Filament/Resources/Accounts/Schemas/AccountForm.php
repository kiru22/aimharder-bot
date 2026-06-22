<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Contraseña de AimHarder. Se guarda cifrada.'),
                TextInput::make('subdomain')
                    ->default('hybridboxgrau')
                    ->required(),
                TextInput::make('box_id')
                    ->numeric()
                    ->default(8244)
                    ->required(),
                Toggle::make('active')
                    ->default(true),
            ]);
    }
}
