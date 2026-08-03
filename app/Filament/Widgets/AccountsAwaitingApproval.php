<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: buyer accounts waiting to be approved.
 *
 * Approving sets the credit limit at the same time, because an active account
 * with a zero limit is an account that cannot order — approving without
 * deciding the number just moves the problem to the first order.
 */
class AccountsAwaitingApproval extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Akun menunggu persetujuan')
            ->emptyStateHeading('Tidak ada akun menunggu persetujuan')
            ->query(
                Company::query()
                    ->where('status', Company::STATUS_PENDING)
                    ->orderBy('created_at')
            )
            ->columns([
                TextColumn::make('kode')->label('Kode')->searchable(),
                TextColumn::make('nama')->label('Nama')->searchable(),
                TextColumn::make('jenis_usaha')->label('Jenis usaha'),
                TextColumn::make('kota')->label('Kota'),
                TextColumn::make('npwp')->label('NPWP')->placeholder('— belum ada —'),
                TextColumn::make('created_at')->label('Didaftarkan')->since(),
            ])
            ->recordActions([
                Action::make('setujui')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->schema([
                        TextInput::make('credit_limit_rupiah')
                            ->label('Limit kredit (Rp)')
                            ->numeric()
                            ->required()
                            ->default(0),
                        TextInput::make('payment_terms_days')
                            ->label('Termin (hari)')
                            ->numeric()
                            ->required()
                            ->default(30),
                    ])
                    ->visible(fn () => auth()->user()->role()->canOverrideCreditLimit())
                    ->action(function (Company $record, array $data) {
                        $oldLimit = $record->credit_limit_rupiah;

                        $record->forceFill([
                            'status' => Company::STATUS_ACTIVE,
                            'credit_limit_rupiah' => (int) $data['credit_limit_rupiah'],
                            'payment_terms_days' => (int) $data['payment_terms_days'],
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                        ])->save();

                        app(AuditLogger::class)->creditLimitOverride(
                            $record,
                            $oldLimit,
                            $record->credit_limit_rupiah,
                            auth()->user(),
                            'Penetapan limit saat persetujuan akun.',
                        );

                        Notification::make()
                            ->title("{$record->nama} disetujui")
                            ->body('Limit kredit '.Money::format($record->credit_limit_rupiah))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
