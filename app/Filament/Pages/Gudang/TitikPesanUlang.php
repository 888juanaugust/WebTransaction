<?php

declare(strict_types=1);

namespace App\Filament\Pages\Gudang;

use App\Domain\Purchasing\SuggestedPurchaseOrder;
use App\Domain\Stock\ReorderAdvisor;
use App\Domain\Stock\ReorderSuggestion;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * What is running out, grouped by who we would ring about it.
 *
 * A worklist rather than a report. The question is not "how is stock doing" —
 * that is the ageing report — it is "what do I order this morning", and the
 * answer is only useful if it ends in an order rather than in a list somebody
 * retypes into another screen.
 *
 * **Grouped by supplier**, because that is the shape of the decision. Nobody
 * places one order for one part; they place one order per supplier covering
 * everything from that supplier that is short. Grouping by anything else —
 * urgency, brand, category — produces a list that has to be re-sorted by hand
 * before it can be acted on.
 *
 * Parts we have never bought from anybody sit in their own group at the
 * bottom. They still run out; somebody still has to decide who to ring, and
 * the screen cannot decide it for them.
 */
class TitikPesanUlang extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::INVENTORI;

    protected static ?string $navigationLabel = 'Titik pesan ulang';

    protected static ?int $navigationSort = 44;

    protected static ?string $slug = 'titik-pesan-ulang';

    protected string $view = 'filament.pages.gudang.titik-pesan-ulang';

    public function getTitle(): string
    {
        return 'Titik pesan ulang';
    }

    /**
     * Behind the purchasing permission, not the stock one.
     *
     * Everything on this screen is a buying decision, and the quantities on it
     * lead straight to a purchase order. Warehouse count stock; they do not
     * commit the business to spending.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    /**
     * Parts at or below their point, out of stock first.
     *
     * @return list<ReorderSuggestion>
     */
    public function suggestions(): array
    {
        return app(ReorderAdvisor::class)->suggestions();
    }

    /**
     * The same rows, grouped by the supplier we last bought each from.
     *
     * @return array<string, array{supplier: ?Supplier, rows: list<ReorderSuggestion>}>
     */
    public function bySupplier(): array
    {
        $groups = [];

        foreach ($this->suggestions() as $row) {
            /*
             * Prefixed so the key stays a string. PHP casts a numeric string
             * array key to an integer, which would leave this map with mixed
             * key types and every caller guessing which it got.
             */
            $key = $row->supplierId === null ? 'tanpa-pemasok' : "pemasok-{$row->supplierId}";

            $groups[$key]['nama'] = $row->supplierNama ?? 'Belum pernah dibeli dari siapa pun';
            $groups[$key]['supplier_id'] = $row->supplierId;
            $groups[$key]['rows'][] = $row;
        }

        // Parts nobody has ever supplied go last: they need a decision this
        // screen cannot make, and they must not sit above orders that can be
        // placed right now.
        uksort($groups, fn (string $a, string $b) => match (true) {
            $a === 'tanpa-pemasok' => 1,
            $b === 'tanpa-pemasok' => -1,
            default => 0,
        });

        return $groups;
    }

    public function habisCount(): int
    {
        return count(array_filter($this->suggestions(), fn (ReorderSuggestion $s) => $s->isHabis()));
    }

    /** @return array<int, string> */
    public function warehouseOptions(): array
    {
        return Warehouse::query()->where('aktif', true)->orderBy('nama')->pluck('nama', 'id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [$this->aturAction()];
    }

    /**
     * Raise a draft purchase order for everything one supplier is short of.
     *
     * Named per supplier at call time rather than being a single action with a
     * supplier picker: the picker would offer suppliers with nothing to order,
     * and the button belongs beside the rows it covers.
     */
    public function buatDraftAction(): Action
    {
        return Action::make('buatDraft')
            ->label('Buat draf PO')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading('Buat draf pesanan pembelian')
            ->modalDescription(
                'Semua barang yang kurang dari pemasok ini masuk ke satu draf, dalam dus, '
                .'dengan harga terakhir yang tercatat. Draf saja — masih bisa diubah, dan '
                .'belum terkirim ke pemasok.'
            )
            ->schema([
                Select::make('warehouse_id')
                    ->label('Dikirim ke gudang')
                    ->options(fn () => $this->warehouseOptions())
                    ->default(fn () => array_key_first($this->warehouseOptions()))
                    ->required(),
            ])
            ->action(function (array $arguments, array $data) {
                /*
                 * Only the domain call is inside the try. A wider one catches
                 * bugs in the code that reports success and announces them as
                 * a refusal — which is how a purchase order that was created
                 * perfectly well came back as "tidak bisa dibuat".
                 */
                try {
                    $po = app(SuggestedPurchaseOrder::class)->draftFor(
                        supplier: Supplier::query()->findOrFail($arguments['supplier']),
                        warehouse: Warehouse::query()->findOrFail($data['warehouse_id']),
                        actor: auth()->user(),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dibuat')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Draf {$po->nomor} dibuat")
                    ->body($po->lines()->count().' baris, dengan harga terakhir yang tercatat. '
                        .'Periksa harganya sebelum dikirim ke pemasok.')
                    ->success()
                    ->send();

                $this->redirect(PurchaseOrderResource::getUrl('edit', ['record' => $po]));
            });
    }

    /**
     * Overrule the arithmetic for one part.
     *
     * The two cases history cannot see: a line being discontinued, and a
     * figure somebody knows for a reason that has not happened yet.
     */
    public function aturAction(): Action
    {
        return Action::make('atur')
            ->label('Atur satu barang')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading('Atur titik pesan ulang')
            ->modalDescription(
                'Biasanya tidak perlu — titiknya dihitung dari penjualan nyata dan lama '
                .'kirim nyata. Ini untuk barang yang tidak akan dibeli lagi, atau yang '
                .'Anda tahu sesuatu yang belum kelihatan di data.'
            )
            ->schema([
                Select::make('sku')
                    ->label('Barang')
                    ->options(fn () => Product::query()
                        ->where('aktif', true)
                        ->orderBy('kode')
                        ->get()
                        ->mapWithKeys(fn (Product $p) => [$p->kode => "{$p->kode} — {$p->description}"])
                        ->all())
                    ->searchable()
                    ->required(),

                TextInput::make('titik_pesan_ulang_manual')
                    ->label('Titik pesan ulang')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Dalam satuan dasar. Kosongkan untuk kembali ke hitungan otomatis.'),

                Toggle::make('jangan_pesan_ulang')
                    ->label('Jangan pesan ulang lagi')
                    ->helperText('Untuk barang yang dihentikan. Stok sisanya tetap bisa dijual.'),
            ])
            ->action(function (array $data) {
                $product = Product::query()->findOrFail($data['sku']);

                $product->forceFill([
                    'titik_pesan_ulang_manual' => $data['titik_pesan_ulang_manual'] === null
                        || $data['titik_pesan_ulang_manual'] === ''
                            ? null
                            : (int) $data['titik_pesan_ulang_manual'],
                    'jangan_pesan_ulang' => (bool) ($data['jangan_pesan_ulang'] ?? false),
                ])->save();

                Notification::make()
                    ->title("{$product->kode} diperbarui")
                    ->success()
                    ->send();
            });
    }
}
