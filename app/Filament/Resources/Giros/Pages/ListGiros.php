<?php

declare(strict_types=1);

namespace App\Filament\Resources\Giros\Pages;

use App\Domain\Giro\GiroRegister;
use App\Domain\Money;
use App\Filament\Resources\Giros\GiroResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The register, and the two ways a giro gets into it.
 *
 * Two actions rather than one with a direction picker. Receiving a cheque and
 * writing one are different jobs done by the same person on different days,
 * and the fields differ — one asks which customer and which invoice, the other
 * which supplier and which bill. A single form with half its fields hidden
 * behind a radio button is how the wrong counterparty gets picked.
 */
class ListGiros extends ListRecords
{
    protected static string $resource = GiroResource::class;

    public function getTitle(): string
    {
        return 'Bilyet giro';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->terimaAction(),
            $this->terbitkanAction(),
        ];
    }

    /** A customer hands one over. */
    private function terimaAction(): Action
    {
        return Action::make('terima')
            ->label('Terima giro')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->visible(fn () => $this->mayHandle())
            ->schema([
                Select::make('company_id')
                    ->label('Dari pelanggan')
                    ->options(fn () => static::companyOptions())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('invoice_id', null)),

                Select::make('invoice_id')
                    ->label('Atas faktur')
                    ->options(fn (Get $get) => static::invoiceOptions($get('company_id')))
                    ->searchable()
                    ->placeholder('Belum ditentukan')
                    ->helperText(
                        'Kosongkan kalau satu giro menutup beberapa faktur. Saat cair, '
                        .'pembayarannya masuk antrean pencocokan seperti transfer biasa.'
                    ),

                TextInput::make('bank_penerbit')
                    ->label('Bank penerbit')
                    ->required()
                    ->maxLength(60)
                    ->placeholder('mis. BCA'),

                TextInput::make('nomor_warkat')
                    ->label('Nomor warkat')
                    ->required()
                    ->maxLength(40)
                    ->helperText('Nomor yang tercetak di bilyetnya. Tidak boleh dobel.'),

                TextInput::make('nilai_rupiah')
                    ->label('Nilai')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                DatePicker::make('tanggal_terima')
                    ->label('Tanggal diterima')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),

                DatePicker::make('tanggal_jatuh_tempo')
                    ->label('Tanggal jatuh tempo')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->helperText('Tanggal yang tertulis di gironya — baru boleh disetor mulai hari itu.'),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                $this->register(
                    fn () => app(GiroRegister::class)->receive(
                        company: Company::findOrFail($data['company_id']),
                        nilaiRupiah: (int) $data['nilai_rupiah'],
                        bankPenerbit: $data['bank_penerbit'],
                        nomorWarkat: $data['nomor_warkat'],
                        jatuhTempo: Carbon::parse($data['tanggal_jatuh_tempo']),
                        actor: auth()->user(),
                        invoice: $data['invoice_id'] ? Invoice::find($data['invoice_id']) : null,
                        diterima: Carbon::parse($data['tanggal_terima']),
                        catatan: $data['catatan'] ?: null,
                    ),
                    'Giro diterima',
                    'Belum jadi uang. Plafon kredit pelanggan tetap terpakai sampai gironya cair.',
                );
            });
    }

    /** We write one out. */
    private function terbitkanAction(): Action
    {
        return Action::make('terbitkan')
            ->label('Terbitkan giro')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn () => $this->mayHandle())
            ->schema([
                Select::make('supplier_id')
                    ->label('Ke pemasok')
                    ->options(fn () => Supplier::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('supplier_bill_id', null)),

                Select::make('supplier_bill_id')
                    ->label('Atas tagihan')
                    ->options(fn (Get $get) => static::billOptions($get('supplier_id')))
                    ->searchable()
                    ->placeholder('Belum ditentukan'),

                TextInput::make('bank_penerbit')
                    ->label('Bank kita')
                    ->required()
                    ->maxLength(60)
                    ->placeholder('mis. BCA'),

                TextInput::make('nomor_warkat')
                    ->label('Nomor warkat')
                    ->required()
                    ->maxLength(40),

                TextInput::make('nilai_rupiah')
                    ->label('Nilai')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                DatePicker::make('tanggal_terima')
                    ->label('Tanggal diserahkan')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),

                DatePicker::make('tanggal_jatuh_tempo')
                    ->label('Tanggal jatuh tempo')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->helperText('Pastikan rekening sudah terisi pada tanggal ini.'),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                $this->register(
                    fn () => app(GiroRegister::class)->issue(
                        supplier: Supplier::findOrFail($data['supplier_id']),
                        nilaiRupiah: (int) $data['nilai_rupiah'],
                        bankPenerbit: $data['bank_penerbit'],
                        nomorWarkat: $data['nomor_warkat'],
                        jatuhTempo: Carbon::parse($data['tanggal_jatuh_tempo']),
                        actor: auth()->user(),
                        bill: $data['supplier_bill_id'] ? SupplierBill::find($data['supplier_bill_id']) : null,
                        diserahkan: Carbon::parse($data['tanggal_terima']),
                        catatan: $data['catatan'] ?: null,
                    ),
                    'Giro diterbitkan',
                    'Uangnya masih di rekening, tapi sudah terikat tanggal jatuh tempo.',
                );
            });
    }

    private function register(callable $action, string $title, string $body): void
    {
        try {
            $giro = $action();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Giro tidak bisa dicatat')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title("{$title}: {$giro->nomor}")
            ->body($body)
            ->success()
            ->send();
    }

    private function mayHandle(): bool
    {
        return auth()->user()?->role()->canHandleGiro() ?? false;
    }

    /** @return array<int, string> */
    private static function companyOptions(): array
    {
        return Company::query()
            ->where('status', Company::STATUS_ACTIVE)
            ->orderBy('nama')
            ->pluck('nama', 'id')
            ->all();
    }

    /**
     * That customer's open invoices, with what is left on each.
     *
     * Only open ones: a giro against a settled invoice is somebody picking the
     * wrong row, and offering it invites exactly that.
     *
     * @return array<int, string>
     */
    private static function invoiceOptions(mixed $companyId): array
    {
        if (! $companyId) {
            return [];
        }

        return Invoice::query()
            ->where('company_id', $companyId)
            ->where('status', Invoice::STATUS_OPEN)
            ->orderBy('due_date')
            ->get()
            ->mapWithKeys(fn (Invoice $invoice) => [
                $invoice->id => sprintf(
                    '%s · sisa %s · jatuh tempo %s',
                    $invoice->nomor,
                    Money::format($invoice->amountOutstanding()),
                    $invoice->due_date->format('d/m/Y'),
                ),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function billOptions(mixed $supplierId): array
    {
        if (! $supplierId) {
            return [];
        }

        return SupplierBill::query()
            ->where('supplier_id', $supplierId)
            ->where('status', SupplierBill::STATUS_OPEN)
            ->whereNotNull('posted_at')
            ->orderBy('due_date')
            ->get()
            ->mapWithKeys(fn (SupplierBill $bill) => [
                $bill->id => sprintf(
                    '%s · sisa %s · jatuh tempo %s',
                    $bill->nomor,
                    Money::format($bill->amountOutstanding()),
                    $bill->due_date->format('d/m/Y'),
                ),
            ])
            ->all();
    }
}
