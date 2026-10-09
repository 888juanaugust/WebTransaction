<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SettlementClaims\Pages;

use App\Client\Domain\Claims\SettlementClaims;
use App\Client\Domain\Claims\TwoKeys;
use App\Client\Filament\Resources\SettlementClaims\SettlementClaimResource;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\SalesReceipts\SalesReceiptResource;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/** One claim: what the seat said, and Finance's key to verify it into a receipt or to reject it. */
class ViewSettlementClaim extends ViewRecord
{
    protected static string $resource = SettlementClaimResource::class;

    protected string $view = 'client.resources.settlement-claims.view';

    public function getTitle(): string
    {
        return __('Settlement claim #:id', ['id' => $this->record->id]);
    }

    public function mayDecide(): bool
    {
        return app(TwoKeys::class)->mayDecide($this->record, auth()->user(), CentralScreen::SettlementClaims);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Verify and record the receipt'))
                ->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => $this->mayDecide())
                ->modalHeading(__('Verify and record the receipt'))
                ->modalDescription(fn () => __(':amount against :number, in your name.', ['amount' => Format::money((int) $this->record->amount), 'number' => $this->record->invoice?->number]))
                ->schema([
                    Select::make('bank_account_id')->label(__('Money went to'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false),
                    DatePicker::make('trans_date')->label(__('Receipt date'))->native(false)->required()->default(today()),
                    Textarea::make('note')->label(__('fields.memo'))->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        $receipt = app(SettlementClaims::class)->verify($this->record, auth()->user(), (int) $data['bank_account_id'], (string) $data['trans_date'], $data['note'] ?? null);
                        Notification::make()->title(__('Receipt :number recorded', ['number' => $receipt->number]))->success()->send();
                        $this->redirect(SalesReceiptResource::getUrl('edit', ['record' => $receipt]));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot verify'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('reject')
                ->label(__('Reject'))
                ->icon('heroicon-m-x-circle')->color('danger')
                ->visible(fn () => $this->mayDecide())
                ->schema([Textarea::make('note')->label(__('Why'))->rows(3)->required()])
                ->action(function (array $data): void {
                    try {
                        app(SettlementClaims::class)->reject($this->record, auth()->user(), (string) $data['note']);
                        Notification::make()->title(__('Claim rejected'))->success()->send();
                        $this->redirect(SettlementClaimResource::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot reject'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
