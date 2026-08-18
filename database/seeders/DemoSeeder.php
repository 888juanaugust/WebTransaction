<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Banking\BankReconciler;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Giro\GiroRegister;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseOrderFlow;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Uom\Unit;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\PriceTierItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A believable day in the life of the business, so it can be shown to somebody.
 *
 * Deliberately NOT part of DatabaseSeeder. `migrate --seed` gives you staff
 * logins, a warehouse and price tiers and nothing else, because a seeded price
 * is a price nobody approved and real prices arrive through a reviewed import.
 * That rule is right, and it also means a fresh install opens onto empty
 * screens — impossible to demonstrate. This fills the gap without weakening it:
 * run it explicitly, never in production, and every price in here is invented.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * What it produces is shaped by the dashboard rather than by the schema. Every
 * queue on the admin home has something in it, every screen has something to
 * look at, and the purchase order carries a real variance so the three-way
 * match has something to find. See docs/DEMO.md for the walkthrough.
 */
class DemoSeeder extends Seeder
{
    /** Invented, and obviously so — nothing here came from a supplier. */
    private const CATALOGUE = [
        // [kode, merk, kategori, tipe, mobil, part number, deskripsi, isi/dus, harga]
        ['YH-1001', 'YUHOLI', 'HYDRAULIC PART', 'Master Rem', 'AVANZA', 'MC-1001', 'Master rem depan', 12, 412_500],
        ['YH-1002', 'YUHOLI', 'HYDRAULIC PART', 'Master Rem', 'XENIA', 'MC-1002', 'Master rem belakang', 12, 398_000],
        ['YH-1003', 'YUHOLI', 'HYDRAULIC PART', 'Wheel Cylinder', 'INNOVA', 'WC-2001', 'Wheel cylinder', 24, 156_000],
        ['OS-2001', 'OSBORN', 'SUSPENSION PART', 'Shock Absorber', 'L300', 'SA-2001', 'Shock absorber depan', 6, 820_000],
        ['OS-2002', 'OSBORN', 'SUSPENSION PART', 'Shock Absorber', 'L300', 'SA-2002', 'Shock absorber belakang', 6, 875_000],
        ['BD-3001', 'BDAX', 'BEARING PART', 'Bearing Roda', 'XENIA', 'BR-3001', 'Bearing roda depan', 18, 245_000],
        ['BD-3002', 'BDAX', 'BEARING PART', 'Bearing Roda', 'AVANZA', 'BR-3002', 'Bearing roda belakang', 18, 232_000],
        ['ST-4001', 'STAVO', 'ELECTRIC PART', 'Kabel Busi', 'INNOVA', 'KB-4001', 'Kabel busi set', 12, 168_000],
        ['ST-4002', 'STAVO', 'ELECTRIC PART', 'Alternator', 'GRANMAX', 'AL-4002', 'Alternator 70A', 4, 1_875_000],
        ['SV-5001', 'SERVO', 'SUSPENSION PART', 'Tie Rod', 'AVANZA', 'TR-5001', 'Tie rod end', 20, 245_000],
        ['SX-6001', 'STAVIX', 'SUSPENSION PART', 'Lower Arm', 'CARRY', 'LA-6001', 'Lower arm kiri', 8, 515_000],
        ['AS-7001', 'ASTRO', 'ELECTRIC PART', 'Starter', 'L300', 'SM-7001', 'Starter motor', 4, 2_150_000],
    ];

    public function run(): void
    {
        $this->refuseInProduction();

        $staff = $this->staff();
        $warehouse = Warehouse::query()->where('kode', 'GD-PUSAT')->firstOrFail();

        $this->catalogue();
        $this->tierPricing();

        $supplier = $this->supplier();
        $companies = $this->customers();

        // Stock has to exist before anything can be sold, and it has to arrive
        // through a receipt so the average cost is real rather than invented.
        $this->openingStock($supplier, $warehouse, $staff['finance']);

        $this->ordersInEveryState($companies, $warehouse, $staff);
        $this->purchaseChainWithAVariance($supplier, $warehouse, $staff['finance']);
        $this->girosInTheDrawer($companies, $supplier, $staff['finance']);
        $this->bankStatements($companies, $staff['finance']);

        $this->command?->info('Demo data ready. See docs/DEMO.md for the walkthrough.');
    }

    /**
     * Never against real data.
     *
     * This writes prices, orders and stock movements. Running it on a live
     * database would mix invented figures into a real ledger, and the ledgers
     * here are append-only — there is no clean way back out.
     */
    private function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoSeeder refuses to run in production. It writes invented prices into '
                .'append-only ledgers, and there is no clean way to remove them afterwards.'
            );
        }

        if (Order::query()->exists()) {
            throw new RuntimeException(
                'This database already has orders in it. Run DemoSeeder on a fresh database '
                .'(php artisan migrate:fresh --seed) so demo figures cannot be confused with real ones.'
            );
        }
    }

    /** @return array<string, User> */
    private function staff(): array
    {
        $staff = [];

        foreach (Role::cases() as $role) {
            $staff[$role->value] = User::query()->where('role', $role->value)->firstOrFail();
        }

        return $staff;
    }

    private function catalogue(): void
    {
        $version = PriceListVersion::query()->create([
            'effective_from' => now()->subMonth()->startOfMonth()->toDateString(),
            'published_at' => now()->subMonth()->startOfMonth(),
            'published_by' => User::query()->where('role', Role::Owner->value)->value('id'),
            'status' => 'published',
            'note' => 'Data demo — bukan daftar harga sungguhan.',
        ]);

        foreach (self::CATALOGUE as [$kode, $merk, $kategori, $tipe, $mobil, $pn, $desc, $isi, $harga]) {
            Product::query()->create([
                'kode' => $kode, 'merk' => $merk, 'kategori' => $kategori,
                'tipe_produk' => $tipe, 'mobil' => $mobil, 'part_number' => $pn,
                'description' => $desc, 'qty_per_ctn' => $isi, 'satuan_dasar' => 'PCS',
                'aktif' => true,
            ]);

            PriceListItem::query()->create([
                'version_id' => $version->id, 'kode' => $kode,
                'harga' => $harga, 'qty_per_ctn' => $isi, 'aktif' => true,
            ]);
        }
    }

    /**
     * One quantity break, so the catalogue shows a price that changes with the
     * order size and `resolvePrice` has something to explain.
     */
    private function tierPricing(): void
    {
        $distributor = PriceTier::query()->where('kode', 'DIST')->firstOrFail();

        PriceTierItem::query()->create([
            'price_tier_id' => $distributor->id,
            'kode' => 'YH-1001',
            'min_qty_base' => 60,
            'harga' => 375_000,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create([
            'kode' => 'SUP-0001',
            'nama' => 'PT Anugerah Sparepart',
            'nama_kontak' => 'Bpk. Andi Wijaya',
            'telepon' => '+62 21 5550101',
            'email' => 'sales@anugerah.example',
            'alamat' => 'Jl. Industri Raya No. 8, Bekasi',
            'payment_terms_days' => 45,
            'aktif' => true,
        ]);
    }

    /** @return array<string, Company> */
    private function customers(): array
    {
        $tiers = PriceTier::query()->pluck('id', 'kode');
        $owner = User::query()->where('role', Role::Owner->value)->value('id');

        $rows = [
            ['bengkel', 'PLG-0001', 'Bengkel Jaya Motor', 'BENGKEL', 45_000_000, 30, Company::STATUS_ACTIVE],
            ['toko', 'PLG-0002', 'Toko Sparepart Makmur', 'TOKO', 120_000_000, 30, Company::STATUS_ACTIVE],
            ['distributor', 'PLG-0003', 'CV Sinar Distribusi', 'DIST', 350_000_000, 45, Company::STATUS_ACTIVE],
            // Sits in the "Accounts awaiting approval" queue on the dashboard.
            ['baru', 'PLG-0004', 'Bengkel Sumber Rejeki', 'BENGKEL', 25_000_000, 14, Company::STATUS_PENDING],
        ];

        $companies = [];

        foreach ($rows as [$key, $kode, $nama, $tier, $limit, $terms, $status]) {
            $company = Company::query()->create([
                'kode' => $kode,
                'nama' => $nama,
                'jenis_usaha' => $tier === 'DIST' ? 'Distributor' : ($tier === 'TOKO' ? 'Toko sparepart' : 'Bengkel'),
                'npwp' => '0'.random_int(1, 9).'.'.random_int(100, 999).'.'.random_int(100, 999).'.'
                    .random_int(1, 9).'-'.random_int(100, 999).'.000',
                'nama_wajib_pajak' => strtoupper($nama),
                'alamat_pajak' => 'Jl. Contoh Demo No. '.random_int(1, 99).', Jakarta',
                'alamat_kirim' => 'Jl. Contoh Demo No. '.random_int(1, 99).', Jakarta',
                'kota' => 'Jakarta',
                'telepon' => '+62 21 555'.random_int(1000, 9999),
                'email' => str_replace(' ', '', strtolower($key)).'@pelanggan.example',
                'nama_kontak' => 'Ibu Sari',
                'price_tier_id' => $tiers[$tier] ?? null,
                'credit_limit_rupiah' => $limit,
                'payment_terms_days' => $terms,
                'status' => $status,
                'approved_at' => $status === Company::STATUS_ACTIVE ? now()->subMonths(6) : null,
                'approved_by' => $status === Company::STATUS_ACTIVE ? $owner : null,
            ]);

            // A portal login for the active customers, so the buyer side can
            // be shown from the customer's own screen.
            if ($status === Company::STATUS_ACTIVE) {
                CustomerUser::query()->create([
                    'company_id' => $company->id,
                    'name' => 'Ibu Sari',
                    'email' => $key.'@pembeli.example',
                    'password' => Hash::make('password'),
                    'telepon' => '+62 812 3456 '.random_int(1000, 9999),
                    'is_active' => true,
                ]);
            }

            $companies[$key] = $company;
        }

        return $companies;
    }

    /**
     * Opening stock, entered the way a real business would enter it: as a goods
     * receipt at a known cost, so the moving average is populated and gross
     * margin can actually be shown.
     */
    private function openingStock(Supplier $supplier, Warehouse $warehouse, User $finance): void
    {
        $receipt = GoodsReceipt::query()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextGoodsReceiptNumber(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_terima' => now()->subWeeks(3)->toDateString(),
            'nomor_faktur_supplier' => 'INV/AS/2026/0781',
            'catatan' => 'Saldo awal persediaan (data demo).',
            'created_by' => $finance->id,
        ]);

        foreach (self::CATALOGUE as $i => [$kode, , , , , , , $isi, $harga]) {
            // Bought at roughly 70% of the selling price — a believable
            // wholesale margin, and it makes the margin figures non-trivial.
            $costPerCarton = (int) round($harga * 0.7) * $isi;
            $cartons = [6, 4, 8, 3, 3, 5, 5, 6, 2, 4, 3, 2][$i] ?? 4;

            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'sku' => $kode,
                'urutan' => $i + 1,
                'ordered_unit' => Unit::Ctn,
                'ordered_qty' => $cartons,
                'qty_per_ctn_snapshot' => $isi,
                'satuan_dasar_snapshot' => 'PCS',
                'qty_base' => $cartons * $isi,
                'unit_cost_rupiah' => $costPerCarton,
                'line_value_rupiah' => $cartons * $costPerCarton,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $finance);
    }

    /**
     * One order in each state the dashboard cares about, so every queue has
     * something in it and the whole chain can be walked without waiting.
     *
     * @param  array<string, Company>  $companies
     * @param  array<string, User>  $staff
     */
    private function ordersInEveryState(array $companies, Warehouse $warehouse, array $staff): void
    {
        $machine = app(OrderStateMachine::class);
        $sales = $staff[Role::Sales->value];
        $finance = $staff[Role::Finance->value];
        $gudang = $staff[Role::Warehouse->value];

        // 1. Awaiting approval — the top dashboard queue.
        $this->order($companies['bengkel'], $warehouse, $sales, [
            ['YH-1001', Unit::Pcs, 24], ['ST-4001', Unit::Pcs, 12],
        ], 'PO-BJM-8821');

        // 2. Confirmed: priced and stock reserved, not yet billed.
        $confirmed = $this->order($companies['toko'], $warehouse, $sales, [
            ['OS-2002', Unit::Ctn, 2], ['BD-3001', Unit::Pcs, 18],
        ], 'PO-TSM-4410');
        $machine->confirm($confirmed->refresh(), $sales);

        // 3. Awaiting payment: invoice issued, VA provisioned, unpaid.
        $billed = $this->order($companies['distributor'], $warehouse, $sales, [
            ['YH-1001', Unit::Ctn, 6], ['SV-5001', Unit::Pcs, 40],
        ], 'PO-CSD-1907');
        $machine->confirm($billed->refresh(), $sales);
        $machine->awaitPayment($billed->refresh(), $finance);

        // 4. Paid — sits in "ready to pick" for the warehouse.
        $paid = $this->order($companies['bengkel'], $warehouse, $sales, [
            ['BD-3002', Unit::Pcs, 18], ['ST-4002', Unit::Pcs, 2],
        ], 'PO-BJM-8790');
        $machine->confirm($paid->refresh(), $sales);
        $machine->awaitPayment($paid->refresh(), $finance);
        $this->settle($paid->refresh(), $finance);

        // 5. Completed, three weeks ago — gives the buyer portal something to
        //    reorder, and the costing something to have already sold.
        $done = $this->order($companies['toko'], $warehouse, $sales, [
            ['YH-1002', Unit::Pcs, 24], ['OS-2001', Unit::Ctn, 1], ['SX-6001', Unit::Pcs, 8],
        ], 'PO-TSM-4180');
        $machine->confirm($done->refresh(), $sales);
        $machine->awaitPayment($done->refresh(), $finance);
        $this->settle($done->refresh(), $finance);
        $machine->ship($done->refresh(), $gudang);
        $machine->complete($done->refresh(), $gudang);

        // 6. An overdue invoice, so the AR queue is not empty. Backdated on the
        //    invoice rather than the order, because the order flow is what it
        //    is and the due date is what the queue reads.
        $overdue = $this->order($companies['distributor'], $warehouse, $sales, [
            ['AS-7001', Unit::Pcs, 2],
        ], 'PO-CSD-1755');
        $machine->confirm($overdue->refresh(), $sales);
        $machine->awaitPayment($overdue->refresh(), $finance);

        $overdue->refresh()->invoice?->forceFill([
            'issued_on' => now()->subDays(52)->toDateString(),
            'due_date' => now()->subDays(22)->toDateString(),
        ])->save();

        /*
         * 7. Money in that nobody has allocated yet.
         *
         * A customer transfers without quoting an invoice number — routine, and
         * the reason the reconciliation queue exists. Without one the dashboard
         * shows an empty state on the queue that is arguably the most
         * convincing thing on the screen.
         */
        app(PaymentLedger::class)->recordManualPayment(
            company: $companies['toko'],
            amountRupiah: 5_000_000,
            actor: $finance,
            catatan: 'Transfer masuk tanpa nomor faktur (data demo).',
            paidAt: now()->subDays(2),
        );
    }

    /**
     * @param  list<array{0: string, 1: Unit, 2: int}>  $lines
     */
    private function order(
        Company $company,
        Warehouse $warehouse,
        User $sales,
        array $lines,
        ?string $po = null,
    ): Order {
        $order = Order::query()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $sales->id,
            'sales_user_id' => $sales->id,
            'po_pelanggan' => $po,
        ]);

        foreach ($lines as $i => [$sku, $unit, $qty]) {
            $product = Product::query()->findOrFail($sku);

            OrderLine::query()->create([
                'order_id' => $order->id,
                'sku' => $sku,
                'urutan' => $i + 1,
                'ordered_unit' => $unit,
                'ordered_qty' => $qty,
                'qty_per_ctn_snapshot' => $product->qty_per_ctn,
                'satuan_dasar_snapshot' => $product->satuan_dasar,
                'qty_base' => $unit->toBaseQtyForProduct($qty, $product),
            ]);
        }

        app(OrderStateMachine::class)->submit($order->refresh(), $sales);

        return $order->refresh();
    }

    /**
     * Take an order to `paid`.
     *
     * `paid` is reachable only from the gateway webhook, so this records the
     * money on the ledger the way a manual bank transfer would be recorded and
     * then makes the transition with meta saying plainly that a seeder did it.
     * Nothing here pretends to be Xendit.
     */
    private function settle(Order $order, User $finance): void
    {
        $invoice = $order->invoice()->firstOrFail();

        app(PaymentLedger::class)->recordManualPayment(
            company: $order->company,
            amountRupiah: $invoice->total_rupiah,
            actor: $finance,
            invoice: $invoice,
            catatan: 'Transfer masuk (data demo).',
        );

        app(OrderStateMachine::class)->markPaid($order, [
            'source' => 'demo_seeder',
            'catatan' => 'Data demo — bukan callback gateway.',
        ]);
    }

    /**
     * A purchase order, a short delivery, and a bill at a higher price than the
     * goods were received at — so the three-way match has both kinds of
     * variance to show, which is the whole point of the screen.
     */
    /**
     * Three bilyet giro, chosen to show the three things that matter.
     *
     * One large one against a named invoice and months away — the ordinary
     * case, and the one that proves the customer's credit has *not* come back.
     * One small one already past its date and unbanked, so the dashboard queue
     * and the sidebar badge have something in them. And one we issued to the
     * supplier, so the balance sheet shows both accounts.
     *
     * @param  array<string, Company>  $companies
     */
    private function girosInTheDrawer(array $companies, Supplier $supplier, User $finance): void
    {
        $register = app(GiroRegister::class);
        $distributor = $companies['distributor'];

        $invoice = Invoice::query()
            ->where('company_id', $distributor->id)
            ->where('status', Invoice::STATUS_OPEN)
            ->orderByDesc('total_rupiah')
            ->first();

        $register->receive(
            company: $distributor,
            nilaiRupiah: 20_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'AB123456',
            jatuhTempo: now()->addDays(54),
            actor: $finance,
            invoice: $invoice,
            diterima: now()->subDays(6),
            catatan: 'Giro 60 hari, disepakati lewat WhatsApp',
        );

        // Due three days ago and still in the drawer: this is what puts the
        // queue on the dashboard and the badge in the sidebar.
        $register->receive(
            company: $distributor,
            nilaiRupiah: 8_500_000,
            bankPenerbit: 'Mandiri',
            nomorWarkat: 'CD778899',
            jatuhTempo: now()->subDays(3),
            actor: $finance,
            diterima: now()->subDays(63),
        );

        $bill = SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierBill::STATUS_OPEN)
            ->first();

        $register->issue(
            supplier: $supplier,
            nilaiRupiah: 6_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'KL445566',
            jatuhTempo: now()->addDays(20),
            actor: $finance,
            bill: $bill,
            diserahkan: now()->subDays(4),
        );
    }

    /**
     * Two bank statements: one signed off, one still open with a difference.
     *
     * The finished one exists so the screen is not showing its own empty state
     * — a system that has never once proved its bank account is exactly what
     * the warning on that page is about, and demoing the warning teaches
     * nobody how the work is done.
     *
     * The open one carries a Rp 17.500 difference, because a reconciliation
     * that balances on the first click shows only half the feature. What
     * somebody needs to see is the hint naming a bank charge and the button
     * that records it, which is the half that changes the books.
     *
     * The statement balances are computed rather than invented: this seeder
     * cannot know what the demo ledger happens to add up to, and a hard-coded
     * figure would leave the screen showing a difference of several million
     * that means nothing.
     *
     * @param  array<string, Company>  $companies
     */
    private function bankStatements(array $companies, User $finance): void
    {
        $reconciler = app(BankReconciler::class);
        $ledger = app(Ledger::class);

        // Yesterday's, signed off. Everything the bank had seen by then is
        // ticked, so the closing balance is simply what the books said.
        $kemarin = now()->subDay()->startOfDay();

        $selesai = $reconciler->open(
            $kemarin,
            $ledger->balanceOf(AccountCode::BANK, $kemarin),
            $finance,
            'Rekening koran BCA (data demo).',
        );

        $reconciler->tickAll($selesai, $finance);
        $reconciler->finalise($selesai->refresh(), $finance);

        // Today's, still open. The bank took an administration fee nobody
        // entered, so the books read Rp 17.500 higher than the statement.
        $hariIni = now()->startOfDay();

        $draft = $reconciler->open(
            $hariIni,
            $ledger->balanceOf(AccountCode::BANK, $hariIni) - 17_500,
            $finance,
            'Rekening koran BCA (data demo) — masih ada selisih.',
        );

        $reconciler->tickAll($draft, $finance);

        /*
         * And one receipt entered after the statement was printed, so the
         * screen has a genuine setoran dalam perjalanan rather than a row of
         * zeroes — the row that is the reason the arithmetic exists at all.
         *
         * It does not move the difference by a rupiah, which is the thing
         * worth seeing: the receipt raises the book balance and the
         * outstanding deposits by the same amount, so they cancel. A deposit
         * in transit is not a discrepancy. It is timing.
         */
        app(PaymentLedger::class)->recordManualPayment(
            company: $companies['bengkel'],
            amountRupiah: 4_250_000,
            actor: $finance,
            catatan: 'Transfer sore, setelah rekening koran dicetak (data demo).',
        );
    }

    private function purchaseChainWithAVariance(Supplier $supplier, Warehouse $warehouse, User $finance): void
    {
        $flow = app(PurchaseOrderFlow::class);

        $po = PurchaseOrder::query()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextPurchaseOrderNumber(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_po' => now()->subDays(10)->toDateString(),
            'tanggal_diharapkan' => now()->subDays(3)->toDateString(),
            'referensi_supplier' => 'QUO-AS-2026-0442',
            'created_by' => $finance->id,
        ]);

        foreach ([
            ['YH-1001', 10, 12, 3_465_000],   // 10 cartons of 12
            ['OS-2002', 5, 6, 3_675_000],     // ordered 5, only 3 arrive
        ] as $i => [$sku, $cartons, $isi, $costPerCarton]) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $po->id,
                'sku' => $sku,
                'urutan' => $i + 1,
                'ordered_unit' => Unit::Ctn,
                'ordered_qty' => $cartons,
                'qty_per_ctn_snapshot' => $isi,
                'satuan_dasar_snapshot' => 'PCS',
                'qty_base' => $cartons * $isi,
                'unit_cost_rupiah' => $costPerCarton,
                'line_value_rupiah' => $cartons * $costPerCarton,
            ]);
        }

        $flow->send($po->refresh(), $finance);

        // The delivery: the first line in full, the second short by two cartons.
        $receipt = GoodsReceipt::query()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextGoodsReceiptNumber(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'tanggal_terima' => now()->subDays(4)->toDateString(),
            'nomor_surat_jalan_supplier' => 'SJ/AS/2026/1180',
            'created_by' => $finance->id,
        ]);

        $delivered = [10, 3];

        foreach ($po->refresh()->lines as $i => $line) {
            $cartons = $delivered[$i];

            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'purchase_order_line_id' => $line->id,
                'sku' => $line->sku,
                'urutan' => $i + 1,
                'ordered_unit' => Unit::Ctn,
                'ordered_qty' => $cartons,
                'qty_per_ctn_snapshot' => $line->qty_per_ctn_snapshot,
                'satuan_dasar_snapshot' => 'PCS',
                'qty_base' => $cartons * (int) $line->qty_per_ctn_snapshot,
                'unit_cost_rupiah' => $line->unit_cost_rupiah,
                'line_value_rupiah' => $cartons * $line->unit_cost_rupiah,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $finance);

        // The supplier's invoice: the first line 5% dearer than agreed.
        $bill = SupplierBill::query()->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextSupplierBillNumber(),
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $po->id,
            'nomor_faktur_supplier' => 'INV/AS/2026/0822',
            'nomor_faktur_pajak' => '0100012600000442',
            'tanggal_faktur' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(42)->toDateString(),
            'created_by' => $finance->id,
        ]);

        foreach ($receipt->refresh()->lines as $i => $line) {
            SupplierBillLine::query()->create([
                'supplier_bill_id' => $bill->id,
                'goods_receipt_line_id' => $line->id,
                'sku' => $line->sku,
                'urutan' => $i + 1,
                'qty_base' => $line->qty_base,
                'unit_cost_rupiah' => $line->unit_cost_rupiah,
                'line_total_rupiah' => $i === 0
                    ? (int) round($line->line_value_rupiah * 1.05)
                    : $line->line_value_rupiah,
            ]);
        }

        app(SupplierBillPoster::class)->post($bill->refresh(), $finance);
    }
}
