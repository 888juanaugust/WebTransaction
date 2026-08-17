<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandedCosts\Pages;

use App\Domain\Money;
use App\Domain\Purchasing\AllocationBasis;
use App\Domain\Purchasing\LandedCostAllocator;
use App\Filament\Resources\LandedCosts\LandedCostResource;
use App\Models\GoodsReceipt;
use App\Models\SupplierBillLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ListLandedCosts extends ListRecords
{
    protected static string $resource = LandedCostResource::class;

    public function getTitle(): string
    {
        return 'Biaya perolehan';
    }

    /**
     * Drawing an allocation, rather than a generic Create.
     *
     * Three questions and nothing else: which charge, which goods, and by what
     * rule. The shares are then computed from what those receipts actually
     * contain — a form that accepted typed amounts would let somebody put the
     * freight wherever it flattered the margin.
     *
     * The charge dropdown only offers charges that are billed, posted and not
     * yet spread, which is the same list the clearing account's balance is.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('alokasikan')
                ->label('Alokasikan biaya')
                ->icon(Heroicon::OutlinedTruck)
                ->visible(fn () => auth()->user()?->role()->canAllocateLandedCost() ?? false)
                ->schema([
                    Select::make('supplier_bill_line_id')
                        ->label('Biaya yang mau dibebankan')
                        ->options(fn () => self::chargeOptions())
                        ->required()
                        ->searchable()
                        ->helperText('Baris berjenis biaya dari tagihan pemasok yang sudah diposting dan belum pernah dibebankan.'),

                    Select::make('goods_receipt_ids')
                        ->label('Penerimaan barang yang ditanggung')
                        ->options(fn () => self::receiptOptions())
                        ->multiple()
                        ->required()
                        ->searchable()
                        ->helperText('Barang yang datang bersama biaya ini. Boleh lebih dari satu penerimaan.'),

                    Radio::make('dasar')
                        ->label('Dasar pembagian')
                        ->options(fn () => collect(AllocationBasis::cases())
                            ->mapWithKeys(fn (AllocationBasis $b) => [$b->value => $b->label()])
                            ->all())
                        ->descriptions(fn () => collect(AllocationBasis::cases())
                            ->mapWithKeys(fn (AllocationBasis $b) => [$b->value => $b->description()])
                            ->all())
                        ->default(AllocationBasis::Nilai->value)
                        ->required(),

                    Textarea::make('catatan')
                        ->label('Catatan')
                        ->rows(2)
                        ->placeholder('mis. kontainer MSKU1234567, ongkos laut Surabaya'),
                ])
                ->action(function (array $data) {
                    try {
                        $landedCost = app(LandedCostAllocator::class)->draw(
                            SupplierBillLine::findOrFail($data['supplier_bill_line_id']),
                            GoodsReceipt::query()->whereIn('id', $data['goods_receipt_ids'])->get(),
                            AllocationBasis::from($data['dasar']),
                            auth()->user(),
                            catatan: $data['catatan'] ?: null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Alokasi tidak bisa dibuat')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Alokasi {$landedCost->nomor} dibuat")
                        ->body($landedCost->lines()->count().' baris. Periksa pembagiannya sebelum diposting.')
                        ->success()
                        ->send();

                    $this->redirect(LandedCostResource::getUrl('view', ['record' => $landedCost]));
                }),
        ];
    }

    /** @return array<int, string> */
    private static function chargeOptions(): array
    {
        return app(LandedCostAllocator::class)->unallocated()
            ->mapWithKeys(fn (SupplierBillLine $line) => [
                $line->id => sprintf(
                    '%s · %s · %s',
                    $line->supplierBill?->supplier?->nama ?? '—',
                    $line->deskripsi ?: 'Biaya',
                    Money::format((int) $line->line_total_rupiah),
                ),
            ])
            ->all();
    }

    /**
     * Posted receipts, most recent first.
     *
     * Not scoped to the charge's supplier: the forwarder who bills for the
     * freight is almost never the supplier who sold the goods, which is the
     * whole reason this document exists separately from the bill.
     */
    private static function receiptOptions(): array
    {
        return GoodsReceipt::query()
            ->with('supplier')
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->orderByDesc('tanggal_terima')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (GoodsReceipt $receipt) => [
                $receipt->id => sprintf(
                    '%s · %s · %s',
                    $receipt->nomor,
                    $receipt->supplier?->nama ?? '—',
                    Money::format((int) $receipt->total_value_rupiah),
                ),
            ])
            ->all();
    }
}
