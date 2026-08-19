<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierCreditNotes\Pages;

use App\Domain\Accounting\AccountCode;
use App\Domain\Purchasing\SupplierCreditNoteIssuer;
use App\Filament\Resources\SupplierCreditNotes\SupplierCreditNoteResource;
use App\Models\Account;
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

class ListSupplierCreditNotes extends ListRecords
{
    protected static string $resource = SupplierCreditNoteResource::class;

    public function getTitle(): string
    {
        return 'Nota kredit pemasok';
    }

    protected function getHeaderActions(): array
    {
        return [$this->catatAction()];
    }

    private function catatAction(): Action
    {
        return Action::make('catat')
            ->label('Catat nota kredit')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('Catat nota kredit pemasok')
            ->modalDescription(
                'Untuk harga yang dikoreksi pemasok tanpa barang kembali. Kalau barangnya '
                .'benar-benar dikirim balik, pakai retur pembelian — itu yang menurunkan stok.'
            )
            ->schema([
                Select::make('supplier_id')
                    ->label('Pemasok')
                    ->options(fn () => Supplier::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),

                /*
                 * Scoped to the chosen supplier, and to bills that still owe
                 * something. Offering a settled bill would mean offering a
                 * choice the issuer rejects at posting.
                 */
                Select::make('supplier_bill_id')
                    ->label('Atas tagihan (opsional)')
                    ->options(fn (Get $get) => $get('supplier_id')
                        ? SupplierBill::query()
                            ->where('supplier_id', $get('supplier_id'))
                            ->where('status', SupplierBill::STATUS_OPEN)
                            ->orderByDesc('tanggal_faktur')
                            ->pluck('nomor', 'id')
                            ->all()
                        : [])
                    ->searchable()
                    ->helperText('Kosongkan kalau ini rabat umum, bukan koreksi satu tagihan.'),

                DatePicker::make('tanggal')
                    ->label('Tanggal nota')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required(),

                TextInput::make('nomor_nota_supplier')
                    ->label('Nomor nota dari pemasok')
                    ->maxLength(60)
                    ->helperText('Nomor yang tercetak di nota mereka, bukan nomor kita.'),

                TextInput::make('dasar_rupiah')
                    ->label('Nilai sebelum PPN')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                TextInput::make('ppn_rupiah')
                    ->label('PPN')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('Rp')
                    ->helperText('Isi hanya kalau pemasok menerbitkan faktur pajak retur.'),

                Select::make('account')
                    ->label('Lawan jurnal')
                    ->options(fn () => static::creditableAccounts())
                    ->default(AccountCode::SELISIH_HARGA_PEMBELIAN)
                    ->searchable()
                    ->required()
                    ->helperText(
                        'Selisih harga pembelian untuk koreksi harga barang — itu akun yang '
                        .'menerima selisihnya waktu tagihan diposting.'
                    ),

                TextInput::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(200)
                    ->placeholder('mis. Harga dikoreksi sesuai kesepakatan'),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $note = app(SupplierCreditNoteIssuer::class)->draft(
                        supplier: Supplier::query()->findOrFail($data['supplier_id']),
                        tanggal: Carbon::parse($data['tanggal']),
                        accountCode: $data['account'],
                        dasarRupiah: (int) $data['dasar_rupiah'],
                        alasan: $data['alasan'],
                        actor: auth()->user(),
                        bill: isset($data['supplier_bill_id'])
                            ? SupplierBill::query()->find($data['supplier_bill_id'])
                            : null,
                        ppnRupiah: (int) ($data['ppn_rupiah'] ?? 0),
                        nomorNotaSupplier: $data['nomor_nota_supplier'] ?? null,
                        catatan: $data['catatan'] ?? null,
                    );

                    Notification::make()
                        ->title("Draf {$note->nomor} dibuat")
                        ->body('Belum mengubah apa pun sampai diposting.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dicatat')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Which accounts a credit may unwind.
     *
     * Persediaan and Utang Usaha are absent because the issuer refuses them —
     * offering a choice that is then rejected is a screen that lied. Revenue
     * accounts are absent for a plainer reason: a supplier crediting us is not
     * us selling anything.
     */
    public static function creditableAccounts(): array
    {
        return Account::query()
            ->where('dapat_diposting', true)
            ->whereIn('tipe', ['aset', 'beban'])
            ->whereNotIn('kode', [AccountCode::PERSEDIAAN, AccountCode::UTANG_USAHA])
            ->orderBy('kode')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->kode => "{$a->kode} — {$a->nama}"])
            ->all();
    }
}
