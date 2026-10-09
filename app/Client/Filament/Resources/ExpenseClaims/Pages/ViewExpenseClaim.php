<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ExpenseClaims\Pages;

use App\Client\Domain\Claims\ExpenseClaims;
use App\Client\Domain\Claims\TwoKeys;
use App\Client\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Client\Screens\CentralScreen;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Format;
use App\Filament\Resources\CashBank\CashPayments\CashPaymentResource;
use App\Models\GeneralLedger\Account;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/** One expense claim, and Finance's key to pay it or to reject it. */
class ViewExpenseClaim extends ViewRecord
{
    protected static string $resource = ExpenseClaimResource::class;

    protected string $view = 'client.resources.expense-claims.view';

    public function getTitle(): string
    {
        return __('Expense claim #:id', ['id' => $this->record->id]);
    }

    public function mayDecide(): bool
    {
        return app(TwoKeys::class)->mayDecide($this->record, auth()->user(), CentralScreen::ExpenseClaims);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Verify and pay'))
                ->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => $this->mayDecide())
                ->modalHeading(__('Verify and pay'))
                ->modalDescription(fn () => __(':amount to :name, as a cash payment in your name, today.', ['amount' => Format::money((int) $this->record->amount), 'name' => $this->record->salesUser?->name]))
                ->schema([
                    Select::make('expense_account_id')->label(__('Expense account'))->options(fn () => Account::options(AccountType::Expense, AccountType::OtherExpense))
                        ->default(fn () => ExpenseClaims::defaultExpenseAccount()?->id)->searchable()->required()->native(false),
                    Select::make('bank_account_id')->label(__('Paid from'))->options(fn () => Account::options(AccountType::CashBank))->searchable()->required()->native(false),
                    Textarea::make('note')->label(__('fields.memo'))->rows(2),
                ])
                ->action(function (array $data): void {
                    try {
                        $payment = app(ExpenseClaims::class)->verify($this->record, auth()->user(), (int) $data['expense_account_id'], (int) $data['bank_account_id'], $data['note'] ?? null);
                        Notification::make()->title(__('Payment :number recorded', ['number' => $payment->number]))->success()->send();
                        $this->redirect(CashPaymentResource::getUrl('edit', ['record' => $payment]));
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
                        app(ExpenseClaims::class)->reject($this->record, auth()->user(), (string) $data['note']);
                        Notification::make()->title(__('Claim rejected'))->success()->send();
                        $this->redirect(ExpenseClaimResource::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot reject'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
