<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tax;

use App\Domain\Access\MenuKey;
use App\Domain\Tax\TaxInvoiceMailer;
use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpPage;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Email Tax Invoice: invoices whose tax invoice serial is back, each sent to
 * the customer with the Coretax PDF and the company's own invoice attached.
 * Coretax PDFs are uploaded in bulk and matched by the serial in their name
 * (or text); one that does not match is attached by hand. Sending is queued
 * and logged; an invoice already sent is sent again only when asked.
 */
class EmailTaxInvoice extends ErpPage implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected string $view = 'filament.pages.tax.email-tax-invoice';

    public static function menuKey(): MenuKey
    {
        return MenuKey::EmailTaxInvoice;
    }

    private static function mailer(): TaxInvoiceMailer
    {
        return app(TaxInvoiceMailer::class);
    }

    private static function upload(string $name, bool $multiple): FileUpload
    {
        return FileUpload::make($name)->label($multiple ? __('Coretax PDFs') : __('Coretax PDF'))
            ->disk('local')->directory(TaxInvoiceMailer::UPLOAD_FOLDER)->visibility('private')
            ->acceptedFileTypes(['application/pdf'])->maxSize(10240)
            ->multiple($multiple)->maxFiles($multiple ? 200 : 1)
            ->storeFileNamesIn($name.'_names')
            ->required();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => SalesInvoiceResource::getEloquentQuery()->whereNotNull('nsfp')->where('nsfp', '!=', '')->with('customer'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable(),
                TextColumn::make('nsfp')->label(__('Tax invoice serial'))->fontFamily('mono')->searchable(),
                TextColumn::make('recipient')->label(__('Send to'))->state(fn (SalesInvoice $record) => self::mailer()->recipient($record))->placeholder(__('No email')),
                IconColumn::make('coretax_pdf')->label(__('Coretax PDF'))->boolean()->state(fn (SalesInvoice $record) => filled($record->coretax_pdf_path)),
                TextColumn::make('mail_status')->label(__('fields.status'))->badge()
                    ->state(fn (SalesInvoice $record) => self::mailer()->status($record))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'sent' => __('Sent'), 'queued' => __('Queued'), 'failed' => __('Failed'), default => __('Not sent'),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'queued' => 'info', 'failed' => 'danger', default => 'gray',
                    }),
            ])
            ->filters([
                DocumentListFilters::dateRange(),
                TernaryFilter::make('coretax_pdf_path')->label(__('Coretax PDF'))->nullable(),
            ])
            ->recordActions([
                Action::make('attachPdf')->label(__('Attach PDF'))->icon('heroicon-m-paper-clip')->color('gray')
                    ->visible(fn () => static::canUpdate())
                    ->schema([self::upload('file', false)])
                    ->action(function (SalesInvoice $record, array $data): void {
                        self::mailer()->attach($record, (string) $data['file']);
                        Notification::make()->title(__('Coretax PDF attached to :number', ['number' => $record->number]))->success()->send();
                    }),
                Action::make('send')->label(__('Send'))->icon('heroicon-m-paper-airplane')
                    ->visible(fn (SalesInvoice $record) => static::canUpdate() && ! self::mailer()->wasSent($record))
                    ->action(fn (SalesInvoice $record) => self::queue(collect([$record]), false)),
                Action::make('sendAgain')->label(__('Send again'))->icon('heroicon-m-arrow-path')->color('gray')
                    ->visible(fn (SalesInvoice $record) => static::canUpdate() && self::mailer()->wasSent($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (SalesInvoice $record) => __(':number was already sent to the customer; send it again?', ['number' => $record->number]))
                    ->action(fn (SalesInvoice $record) => self::queue(collect([$record]), true)),
            ])
            ->toolbarActions([
                BulkAction::make('sendSelected')->label(__('Send selected'))->icon('heroicon-m-paper-airplane')
                    ->visible(fn () => static::canUpdate())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => self::queue($records, false)),
            ])
            ->defaultSort('trans_date', 'desc')
            ->emptyStateHeading(__('No tax invoice serials yet'))
            ->emptyStateDescription(__('Invoices appear here once their serial is pasted back on the e-Tax Invoice Export screen.'));
    }

    /** @param  Collection<int, SalesInvoice>  $invoices */
    private static function queue(Collection $invoices, bool $again): void
    {
        $queued = 0;
        $refused = [];
        foreach ($invoices as $invoice) {
            try {
                self::mailer()->queue($invoice, $again);
                $queued++;
            } catch (RuntimeException $e) {
                $refused[] = $e->getMessage();
            }
        }
        if ($queued > 0) {
            Notification::make()->title(__(':count tax invoice(s) queued to send', ['count' => $queued]))->success()->send();
        }
        if ($refused !== []) {
            Notification::make()->title(__('Not sent'))->body(implode("\n", $refused))->warning()->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadPdfs')->label(__('Upload Coretax PDFs'))->icon('heroicon-m-arrow-up-tray')
                ->visible(fn () => static::canUpdate())
                ->schema([self::upload('files', true)])
                ->modalDescription(__('Each PDF is matched to its invoice by the serial in its file name or text; one that matches none is left out, to attach by hand.'))
                ->action(function (array $data): void {
                    $names = (array) ($data['files_names'] ?? []);
                    $files = [];
                    foreach ((array) $data['files'] as $path) {
                        $files[$names[$path] ?? basename((string) $path)] = (string) $path;
                    }
                    $result = self::mailer()->attachMany($files);
                    Notification::make()->title(__(':count PDF(s) attached', ['count' => count($result['matched'])]))->success()->send();
                    if ($result['unmatched'] !== []) {
                        Notification::make()->title(__('Not matched to any invoice'))->body(implode("\n", $result['unmatched']))->warning()->persistent()->send();
                    }
                    $this->resetTable();
                }),
        ];
    }
}
