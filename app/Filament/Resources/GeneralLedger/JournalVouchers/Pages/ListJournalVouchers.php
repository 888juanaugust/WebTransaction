<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\JournalVouchers\Pages;

use App\Domain\Access\BranchLimit;
use App\Domain\Printing\Printable;
use App\Domain\Printing\PrintJob;
use App\Domain\Shared\Format;
use App\Filament\Resources\Company\MemorizedTransactions\MemorizedTransactionResource;
use App\Filament\Resources\GeneralLedger\JournalVouchers\JournalVoucherResource;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Models\Company\MemorizedTransaction;
use App\Models\GeneralLedger\JournalEntry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The standard's Journal Vouchers list: every journal entry in the
 * books, whatever document wrote it, with the source document's number as
 * "Trans. No." and a filter by transaction type. Manual vouchers open to edit.
 */
class ListJournalVouchers extends ListRecords
{
    protected static string $resource = JournalVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New journal voucher'))];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BranchLimit::apply(JournalEntry::query()->active(), auth()->user())->withSum('lines', 'debit'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('source_number')->label(__('Trans. No.'))->fontFamily('mono')->placeholder('—'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('source_type')->label(__('Transaction type'))->badge()->color('gray')->formatStateUsing(fn (string $state) => self::typeLabel($state)),
                TextColumn::make('description')->label(__('fields.description'))->limit(60)->placeholder('—'),
                Rupiah::make('lines_sum_debit')->label(__('fields.total')),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                SelectFilter::make('source_type')->label(__('Transaction type'))
                    ->options(fn () => collect(array_keys(Relation::morphMap()))->mapWithKeys(fn (string $k) => [$k => self::typeLabel($k)])->sort()->all())
                    ->multiple(),
                Filter::make('trans_date')
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false),
                        DatePicker::make('until')->label(__('Until'))->native(false),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('trans_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('trans_date', '<=', $d))),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (JournalEntry $record) => $record->source_type === 'journal_voucher' && JournalVoucherResource::canEdit($record->posting->document))
                    ->url(fn (JournalEntry $record) => JournalVoucherResource::getUrl('edit', ['record' => $record->posting->document_id])),
                Action::make('view')
                    ->label(__('Lines'))
                    ->icon('heroicon-m-eye')
                    ->slideOver()
                    ->modalHeading(fn (JournalEntry $record) => "Journal {$record->number}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(fn (JournalEntry $record) => [
                        TextEntry::make('trans_date')->label(__('fields.trans_date'))->state(Format::date($record->trans_date)),
                        TextEntry::make('description')->label(__('fields.description'))->state($record->description ?: '—'),
                        RepeatableEntry::make('lines')
                            ->label(__('Lines'))
                            ->state($record->lines()->with('account')->get()->map(fn ($l) => [
                                'account' => $l->account->displayName(),
                                'debit' => $l->debit ? Format::number($l->debit) : '',
                                'credit' => $l->credit ? Format::number($l->credit) : '',
                                'memo' => $l->memo,
                            ])->all())
                            ->schema([
                                TextEntry::make('account')->label(__('Account')),
                                TextEntry::make('debit')->label(__('Debit')),
                                TextEntry::make('credit')->label(__('Credit')),
                                TextEntry::make('memo')->label(__('Memo')),
                            ])
                            ->columns(4),
                    ]),
                Action::make('memorize')
                    ->label(__('Memorize'))
                    ->icon('heroicon-m-bookmark')
                    ->color('gray')
                    ->visible(fn (JournalEntry $record) => $record->source_type === 'journal_voucher' && $record->posting?->document !== null
                        && JournalVoucherResource::canCreate() && MemorizedTransactionResource::canCreate())
                    ->schema([
                        TextInput::make('name')->label(__('Template name'))->required()->maxLength(100)->default(fn (JournalEntry $record) => $record->description ?: $record->source_number),
                    ])
                    ->action(function (array $data, JournalEntry $record): void {
                        $voucher = $record->posting->document;
                        MemorizedTransaction::query()->create([
                            'name' => $data['name'],
                            'transaction_type' => 'journal_voucher',
                            'template' => [
                                'description' => $voucher->description,
                                'branch_id' => $voucher->branch_id,
                                'lines' => $voucher->lines->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => $line->debit, 'credit' => $line->credit, 'memo' => $line->memo])->values()->all(),
                            ],
                            'used_all_user' => true,
                            'created_by' => auth()->id(),
                        ]);
                        Notification::make()->title(__(':name memorized', ['name' => $data['name']]))->success()->send();
                    }),
                Action::make('print')
                    ->label(__('Print'))
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn (JournalEntry $record): ?string => $record->posting?->document ? PrintJob::url($record->posting->document) : null, shouldOpenInNewTab: true)
                    ->visible(fn (JournalEntry $record): bool => $record->posting?->document !== null && Printable::aliasOf($record->posting->document) !== null),
            ]);
    }

    public static function typeLabel(string $type): string
    {
        return Format::documentType($type);
    }
}
