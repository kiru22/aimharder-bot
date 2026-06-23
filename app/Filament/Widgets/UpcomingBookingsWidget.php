<?php

namespace App\Filament\Widgets;

use App\Models\BookingRule;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingBookingsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Próximas reservas')
            ->query(BookingRule::query()->where('active', true)->with('account'))
            ->columns([
                TextColumn::make('account.label')
                    ->label('Cuenta')
                    ->searchable(),
                TextColumn::make('class_name')
                    ->label('Clase')
                    ->searchable(),
                TextColumn::make('weekdays')
                    ->label('Días')
                    ->formatStateUsing(function ($state): string {
                        $names = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
                        $days  = is_array($state) ? $state : (json_decode($state, true) ?? []);

                        return implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days));
                    }),
                TextColumn::make('time')
                    ->label('Hora'),
                TextColumn::make('next_occurrence')
                    ->label('Próxima')
                    ->getStateUsing(function (BookingRule $record): string {
                        $next = $record->nextOccurrence();

                        if ($next === null) {
                            return '—';
                        }

                        $next->locale('es');

                        return $next->isoFormat('ddd D MMM HH:mm');
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('time')
                            ->placeholder('18:00')
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
                        TextInput::make('class_name')
                            ->placeholder('CrossFit')
                            ->required(),
                        Toggle::make('insist')
                            ->helperText('Insistir / lista de espera'),
                    ]),

                Action::make('skip_next')
                    ->label('Saltar próxima')
                    ->icon('heroicon-m-forward')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (BookingRule $record): void {
                        $next = $record->nextOccurrence();

                        if ($next === null) {
                            Notification::make()
                                ->title('No hay próxima ocurrencia')
                                ->warning()
                                ->send();

                            return;
                        }

                        $dateStr = $next->format('Y-m-d');
                        $skip    = $record->skip_dates ?? [];
                        $skip[]  = $dateStr;
                        $record->update(['skip_dates' => $skip]);

                        Notification::make()
                            ->title("Reserva del {$dateStr} saltada")
                            ->success()
                            ->send();
                    }),

                Action::make('deactivate')
                    ->label('Desactivar')
                    ->icon('heroicon-m-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (BookingRule $record): void {
                        $record->update(['active' => false]);

                        Notification::make()
                            ->title('Regla desactivada')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
