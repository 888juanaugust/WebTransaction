<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Pages;

use App\Domain\Purchasing\PurchaseReturnIssuer;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Resources\PurchaseReturns\Schemas\PurchaseReturnForm;
use App\Models\GoodsReceipt;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

class ListPurchaseReturns extends ListRecords
{
    protected static string $resource = PurchaseReturnResource::class;

    public function getTitle(): string
    {
        return 'Retur pembelian';
    }

    /**
     * Drawing a return off a delivery, rather than a generic Create.
     *
     * Two questions: which delivery, and why. The lines come from the receipt
     * itself, at the quantities still returnable, and the next screen is where
     * they get cut down.
     *
     * It starts from **everything** rather than nothing. Fewer keystrokes in
     * the usual case, and — more to the point — it fails in the safer
     * direction: forgetting to delete a line leaves you having returned too
     * much, which the supplier will tell you about, rather than having quietly
     * kept goods that stay on the shelf and in the payable.
     *
     * The dropdown only offers posted receipts with something left on them,
     * which is the same population the poster will accept.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('buat')
                ->label('Buat retur')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn () => auth()->user()?->role()->canReturnToSupplier() ?? false)
                ->schema([
                    Select::make('goods_receipt_id')
                        ->label('Penerimaan yang mau diretur')
                        ->options(fn () => PurchaseReturnForm::receiptOptions())
                        ->required()
                        ->searchable()
                        ->helperText(
                            'Penerimaan yang sudah diposting dan masih ada isinya. '
                            .'Semua barisnya ikut dulu — yang tidak jadi diretur dihapus di layar berikutnya.'
                        ),

                    DatePicker::make('tanggal')
                        ->label('Tanggal retur')
                        ->default(now())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),

                    Textarea::make('alasan')
                        ->label('Alasan')
                        ->required()
                        ->rows(2)
                        ->placeholder('mis. 3 dus salah tipe, sudah dikonfirmasi ke pemasok lewat WA')
                        ->helperText('Wajib, dan ikut tercetak di nota retur yang diterima pemasok.'),
                ])
                ->action(function (array $data) {
                    try {
                        $return = app(PurchaseReturnIssuer::class)->draftEverything(
                            GoodsReceipt::findOrFail($data['goods_receipt_id']),
                            auth()->user(),
                            $data['alasan'],
                            // A date picker hands back a string. The issuer
                            // takes a DateTimeInterface, deliberately — the
                            // domain should not be parsing whatever a form
                            // happens to send it — so it is parsed here.
                            Carbon::parse($data['tanggal']),
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Retur tidak bisa dibuat')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Retur {$return->nomor} dibuat")
                        ->body(
                            $return->lines()->count().' baris, '
                            .number_format($return->qtyReturned(), 0, ',', '.')
                            .' unit. Hapus yang tidak jadi diretur sebelum diposting.'
                        )
                        ->success()
                        ->send();

                    $this->redirect(PurchaseReturnResource::getUrl('edit', ['record' => $return]));
                }),
        ];
    }
}
