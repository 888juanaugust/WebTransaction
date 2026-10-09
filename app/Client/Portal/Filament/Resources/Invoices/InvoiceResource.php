<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Invoices;

use App\Client\Portal\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Client\Portal\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Client\Portal\Filament\Support\PortalResource;
use App\Client\Portal\Filament\Support\ScopedToBuyer;
use App\Client\Portal\PortalDocuments;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Invoices: the buyer's own, open ones first, with due dates, balances and the PDF. */
class InvoiceResource extends PortalResource
{
    use ScopedToBuyer;

    protected static ?string $model = SalesInvoice::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $slug = 'invoices';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('Invoices');
    }

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoices');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('number')->label(__('Invoice'))->fontFamily('mono')->searchable(),
                Tanggal::make('trans_date')->label(__('fields.trans_date'))->sortable(),
                Tanggal::make('due_date')->label(__('Due'))->sortable(),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->state(fn (SalesInvoice $r) => self::stateLabel($r))
                    ->color(fn (SalesInvoice $r) => $r->payment_status === 'paid' ? 'success' : ($r->due_date && $r->due_date->isBefore(today()) ? 'danger' : 'warning')),
                Rupiah::make('total')->label(__('fields.total')),
                Rupiah::make('paid_amount')->label(__('Paid')),
                Rupiah::make('balance')->label(__('Balance'))->state(fn (SalesInvoice $r) => $r->balance())->weight('medium'),
            ])
            ->filters([
                TernaryFilter::make('open')->label(__('Open'))->placeholder(__('All'))->trueLabel(__('Open only'))->falseLabel(__('Paid only'))->default(true)
                    ->queries(true: fn (Builder $q) => $q->where('payment_status', '!=', 'paid'), false: fn (Builder $q) => $q->where('payment_status', 'paid')),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Open')),
                Action::make('pdf')->label(__('PDF'))->icon('heroicon-m-arrow-down-tray')->color('gray')->url(fn (SalesInvoice $r) => PortalDocuments::url($r), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading(__('No invoices'));
    }

    public static function stateLabel(SalesInvoice $invoice): string
    {
        if ($invoice->payment_status === 'paid') {
            return __('Paid');
        }
        if ($invoice->due_date && $invoice->due_date->isBefore(today())) {
            return __(':n days late', ['n' => (int) $invoice->due_date->diffInDays(today(), false)]);
        }

        return __('due in :n days', ['n' => max(0, (int) today()->diffInDays($invoice->due_date ?? today(), false))]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
