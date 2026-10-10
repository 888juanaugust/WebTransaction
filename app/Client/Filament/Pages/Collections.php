<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Domain\Debt\CollectionDesk;
use App\Client\Domain\Debt\DebtNotices;
use App\Client\Filament\Resources\SettlementClaims\SettlementClaimResource;
use App\Client\Models\CollectionContact;
use App\Client\Models\DebtNotice;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Sales\CreditCheck;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpPage;
use App\Filament\Support\MoneyInput;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Collections: the unpaid invoices this user chases, bucketed by what to do
 * today — promises due, promises missed, overdue with nobody promising —
 * with the aging state, the last contact and the promise on each. Record a
 * contact, read the history, or file a settlement claim from here.
 */
class Collections extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'client.pages.collections';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowUpRight;

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::Collections;
    }

    public function table(Table $table): Table
    {
        $desk = app(CollectionDesk::class);

        return $table
            ->query(fn () => $desk->chaseable(auth()->user()))
            ->columns([
                TextColumn::make('customer.name')->label(__('fields.customer'))->weight('medium')->searchable(),
                TextColumn::make('number')->label(__('Invoice'))->fontFamily('mono')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                Tanggal::make('due_date')->label(__('Due')),
                TextColumn::make('age')->label(__('Age'))->alignEnd()
                    ->state(fn (SalesInvoice $record) => __(':n days', ['n' => (int) app(AgingDate::class)->issued($record)->diffInDays(today(), false)])),
                Rupiah::make('balance')->label(__('Balance'))->state(fn (SalesInvoice $record) => $record->balance()),
                TextColumn::make('aging')->label(__('Aging'))->badge()
                    ->state(fn (SalesInvoice $record) => $this->agingLabel($record))
                    ->color(fn (SalesInvoice $record) => $this->agingColor($record)),
                TextColumn::make('bucket')->label(__('To do'))->badge()
                    ->state(fn (SalesInvoice $record) => CollectionDesk::bucketLabel($desk->bucket($record)))
                    ->color(fn (SalesInvoice $record) => match ($desk->bucket($record)) {
                        CollectionDesk::DUE_TODAY => 'info',
                        CollectionDesk::MISSED => 'danger',
                        CollectionDesk::UNCONTACTED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_contact')->label(__('Last contact'))->placeholder('—')
                    ->state(fn (SalesInvoice $record) => ($c = $desk->lastContact($record)) ? Format::date($c->contacted_at).' · '.CollectionContact::outcomeLabel($c->outcome) : null),
                TextColumn::make('promise')->label(__('Promise'))->placeholder('—')
                    ->state(fn (SalesInvoice $record) => $this->promiseText($record)),
            ])
            ->filters([
                SelectFilter::make('bucket')->label(__('To do'))
                    ->options([
                        CollectionDesk::DUE_TODAY => CollectionDesk::bucketLabel(CollectionDesk::DUE_TODAY),
                        CollectionDesk::MISSED => CollectionDesk::bucketLabel(CollectionDesk::MISSED),
                        CollectionDesk::UNCONTACTED => CollectionDesk::bucketLabel(CollectionDesk::UNCONTACTED),
                        CollectionDesk::REST => CollectionDesk::bucketLabel(CollectionDesk::REST),
                    ])
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('id', app(CollectionDesk::class)->worklist(auth()->user())[$data['value']] ?? [])
                        : $query),
            ])
            ->defaultSort('trans_date')
            ->recordActions([
                $this->recordAction(),
                Action::make('history')->label(__('History'))->icon('heroicon-m-clock')->color('gray')
                    ->modalHeading(fn (SalesInvoice $record) => __('Contacts about :number', ['number' => $record->number]))
                    ->modalContent(fn (SalesInvoice $record) => view('client.pages.collection-history', [
                        'contacts' => CollectionContact::query()->where('sales_invoice_id', $record->id)->with('user')->latest('contacted_at')->latest('id')->get(),
                        'kept' => app(CollectionDesk::class)->promiseKept($record),
                    ]))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Close')),
                Action::make('claim')->label(__('File a settlement claim'))->icon('heroicon-m-hand-raised')->color('gray')
                    ->visible(fn () => HakAkses::can(CentralScreen::SettlementClaims, Hak::Create))
                    ->url(fn (SalesInvoice $record) => SettlementClaimResource::getUrl('create').'?invoice='.$record->id),
            ])
            ->emptyStateHeading(__('Nothing to chase'))
            ->emptyStateDescription(__('Every invoice of your customers is paid, or none is yours to chase.'));
    }

    private function recordAction(): Action
    {
        return Action::make('record')
            ->label(__('Record contact'))
            ->icon('heroicon-m-phone')
            ->color('primary')
            ->visible(fn () => static::canUpdate() && HakAkses::canSpecial(HakKhusus::SeeCreditData))
            ->modalHeading(fn (SalesInvoice $record) => __('Contact about :number', ['number' => $record->number]))
            ->schema([
                Select::make('method')->label(__('How'))->options(collect(CollectionContact::METHODS)->mapWithKeys(fn (string $m) => [$m => CollectionContact::methodLabel($m)])->all())->required()->native(false),
                Select::make('outcome')->label(__('Outcome'))->options(collect(CollectionContact::OUTCOMES)->mapWithKeys(fn (string $o) => [$o => CollectionContact::outcomeLabel($o)])->all())->required()->native(false)->live(),
                DatePicker::make('promise_date')->label(__('Will pay on'))->native(false)->minDate(today())
                    ->visible(fn (Get $get) => $get('outcome') === CollectionContact::PROMISE)->required(fn (Get $get) => $get('outcome') === CollectionContact::PROMISE),
                MoneyInput::make('promise_amount')->label(__('Promised amount'))->prefix(Format::symbol())
                    ->visible(fn (Get $get) => $get('outcome') === CollectionContact::PROMISE)->helperText(__('Leave empty for the whole balance.')),
                DateTimePicker::make('contacted_at')->label(__('When'))->native(false)->default(now())->seconds(false)->maxDate(now()),
                Textarea::make('note')->label(__('fields.memo'))->rows(2),
            ])
            ->action(function (SalesInvoice $record, array $data): void {
                try {
                    app(CollectionDesk::class)->record($record, auth()->user(), $data);
                    Notification::make()->title(__('Contact recorded'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Cannot record'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    private function promiseText(SalesInvoice $invoice): ?string
    {
        $desk = app(CollectionDesk::class);
        $promise = $desk->promise($invoice);
        if ($promise === null) {
            return null;
        }
        $state = match ($desk->promiseKept($invoice)) {
            true => __('kept'),
            false => __('missed'),
            null => __('open'),
        };

        return Format::date($promise->promise_date).($promise->promise_amount ? ' · '.Format::money($promise->promise_amount) : '').' · '.$state;
    }

    private function agingLabel(SalesInvoice $invoice): string
    {
        $credit = app(CreditCheck::class);
        $age = (int) app(AgingDate::class)->issued($invoice)->diffInDays(today(), false);
        if ($credit->freezeDays() > 0 && $age > $credit->freezeDays()) {
            return __('frozen');
        }
        if (DebtNotice::query()->where('sales_invoice_id', $invoice->id)->exists()) {
            return __('notice sent');
        }
        $freezesOn = app(DebtNotices::class)->freezesOn($invoice);

        return $freezesOn !== null && $credit->noticeDays() > 0 && $age > $credit->noticeDays() ? __('freezes :date', ['date' => Format::date($freezesOn)]) : __('fine');
    }

    private function agingColor(SalesInvoice $invoice): string
    {
        return match ($this->agingLabel($invoice)) {
            __('frozen') => 'danger',
            __('fine') => 'gray',
            default => 'warning',
        };
    }
}
