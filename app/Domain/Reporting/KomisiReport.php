<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Access\Role;
use App\Domain\Catalogue\Golongan;
use App\Domain\Komisi\JenisKomisi;
use App\Domain\Regions\RegionContext;
use App\Models\CommissionRate;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SalesTarget;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What everybody on commission earned on the month's *collected* money —
 * and how each stands against their target.
 *
 * Commission here is paid on settlement, not on invoicing. The operation
 * sells on credit: an invoice is a promise, and paying commission on
 * promises pays people for debt that may never arrive. So the base month is
 * the month the invoice finished settling — the date of its last payment
 * entry — and an invoice reopened by a reversal simply drops back out,
 * which is the claw-back, visible and automatic.
 *
 * Nothing is stored. Following the same rule as debt aging — derived
 * arithmetic, never stored state — komisi is recomputed from the ledgers on
 * every call. What cannot be re-derived is stored instead: the rate a person
 * was on (`commission_rates`, effective-dated, append-only) and the target
 * they were given (`sales_targets`). The rate applied is the one effective
 * on the settlement date, so a raise never rewrites an old month.
 *
 * Four kinds of commission, one settled-invoice list (see `JenisKomisi`):
 *
 * - **Penjualan** — both seats of the customer's team earn on that
 *   customer's settled invoices, each at their own rate. Seats are read from
 *   the company row as it stands today; reassigning a customer moves future
 *   commission with them, and the report says so in a note.
 * - **Supervisor** — one cabang's settled invoices, all of them, customers
 *   with no seat included. **Manajer** — every cabang's.
 * - **Pembelian impor** — settled supplier bills, the lines whose product is
 *   `golongan = impor`, net of PPN. Paid when the supplier is paid, for the
 *   same reason sellers are paid when the customer pays.
 *
 * The base is money the business actually keeps: total minus PPN — tax
 * collected for the state is nobody's sale — minus posted credit notes
 * against the invoice, net of their own PPN. An invoice settled entirely by
 * credit note has no payment entry, no settlement date, and correctly earns
 * nothing. A balance carried in from the old books (`saldo_awal`) earns
 * nobody anything on either side.
 */
class KomisiReport
{
    public function build(Period $period): ReportTable
    {
        $settled = $this->settledInvoices($period);
        $rates = CommissionRate::query()->with(['user', 'region'])->orderBy('berlaku_mulai')->get();

        $rows = array_merge(
            $this->seatRows($settled, $rates->where('jenis', JenisKomisi::Penjualan->value)->groupBy('user_id')),
            $this->jenisRows($period, $settled, $rates->where('jenis', '!=', JenisKomisi::Penjualan->value)),
        );

        // Targets attach per kind: the seat kind's to the sales seat (selling
        // is what was targeted), the other kinds' to whoever holds the rate.
        $targets = SalesTarget::query()
            ->where('tahun', $period->from->year)
            ->where('bulan', $period->from->month)
            ->with('user')
            ->get()
            ->keyBy(fn (SalesTarget $t) => $t->user_id.'|'.($t->jenis ?? JenisKomisi::Penjualan->value));

        foreach ($rows as &$row) {
            $target = $targets->get($row['user_id'].'|'.$row['jenis_target']);

            if ($target !== null) {
                $row['target'] = (int) $target->target_rupiah;
                $row['pencapaian'] = $target->target_rupiah > 0
                    ? round($row['basis'] / $target->target_rupiah * 100, 1)
                    : null;
            }
        }
        unset($row);

        // A person with a target but nothing settled still belongs on the
        // sheet — zero against a target is the row that starts the
        // conversation.
        foreach ($targets as $key => $target) {
            [$userId, $jenis] = explode('|', $key);

            $seen = collect($rows)->contains(fn (array $r) => $r['user_id'] === (int) $userId
                && $r['jenis_target'] === $jenis);

            if ($seen || $target->user === null) {
                continue;
            }

            $jenisKomisi = JenisKomisi::tryFrom($jenis) ?? JenisKomisi::Penjualan;

            $rows[] = [
                'nama' => $target->user->name,
                'peran' => $jenisKomisi === JenisKomisi::Penjualan ? Role::Sales->label() : $jenisKomisi->label(),
                'peran_raw' => $jenisKomisi === JenisKomisi::Penjualan ? Role::Sales->value : $jenisKomisi->value,
                'jenis_target' => $jenis,
                'user_id' => (int) $userId,
                'faktur' => 0,
                'basis' => 0,
                'tarif' => null,
                'komisi' => 0,
                'target' => (int) $target->target_rupiah,
                'pencapaian' => 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['peran'], -$a['basis']] <=> [$b['peran'], -$b['basis']]);

        return new ReportTable(
            judul: 'Komisi & target',
            period: $period,
            columns: [
                ReportColumn::text('nama', 'Nama'),
                ReportColumn::text('peran', 'Peran / jenis'),
                ReportColumn::number('faktur', 'Dokumen lunas'),
                ReportColumn::money('basis', 'Dasar (tanpa PPN)'),
                ReportColumn::money('komisi', 'Komisi'),
                ReportColumn::money('target', 'Target'),
                ReportColumn::percent('pencapaian', 'Capai'),
            ],
            rows: $rows,
            totals: [
                'nama' => 'TOTAL',
                'faktur' => array_sum(array_column($rows, 'faktur')),
                'basis' => array_sum(array_column($rows, 'basis')),
                'komisi' => array_sum(array_column($rows, 'komisi')),
            ],
            catatan: [
                'Komisi dihitung saat faktur LUNAS, bukan saat terbit — tagihan yang belum '
                .'dibayar belum menghasilkan komisi, dan faktur yang dibuka kembali oleh '
                .'pembalikan otomatis keluar dari sini.',
                'Dasar = total faktur tanpa PPN, dikurangi nota kredit atas faktur itu. '
                .'Tarif yang dipakai adalah tarif yang berlaku pada tanggal pelunasan.',
                'Kursi sales/marketing dibaca dari data pelanggan saat ini — memindahkan '
                .'pelanggan memindahkan komisi berikutnya.',
                'Supervisor dihitung atas seluruh faktur lunas satu cabang, Manajer atas semua '
                .'cabang, Pembelian impor atas baris tagihan pemasok lunas untuk barang golongan '
                .'impor — semuanya tanpa PPN dan tanpa saldo awal dari pembukuan lama.',
            ],
        );
    }

    /**
     * Every invoice whose settlement finished inside the period, with what
     * it earns commission on.
     *
     * @return Collection<int, array{invoice: Invoice, region_id: ?int, basis: int, settled_at: Carbon}>
     */
    private function settledInvoices(Period $period): Collection
    {
        /*
         * Every cabang's books, not just the viewer's. A manajer is paid on
         * all of them by definition, and a supervisor's rate names its own
         * cabang whatever the viewer is pinned to. The seat rows keep the
         * viewer's scope — see seatRows() — so a Finance account pinned to
         * one cabang still sees only its own seats, as on every other report.
         */
        $invoices = Invoice::query()
            ->withoutGlobalScope('region')
            ->whereIn('status', [Invoice::STATUS_PAID])
            // A balance carried in from the old books is not a sale anybody
            // here made; settling it earns nobody commission.
            ->bukanSaldoAwal()
            ->with(['company.salesRep', 'company.marketingRep'])
            // The settlement moment is the last payment entry on the invoice.
            ->whereRaw('(select max(paid_at) from payment_entries where payment_entries.invoice_id = invoices.id) between ? and ?', [
                $period->from, $period->to,
            ])
            ->get();

        if ($invoices->isEmpty()) {
            return collect();
        }

        $credits = CreditNote::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->where('status', CreditNote::STATUS_POSTED)
            ->get()
            ->groupBy('invoice_id');

        $out = collect();

        foreach ($invoices as $invoice) {
            /*
             * When the last money reached this faktur, read through the
             * allocations. Asking the entries directly would miss every
             * invoice settled as part of a transfer covering several — those
             * entries name no single faktur — and the seller would lose the
             * commission on exactly the payments that arrive in a lump at
             * month end.
             */
            $settledAt = $invoice->allocations()
                // The allocation rows carry the region scope too; a manajer's
                // basis spans every cabang, so it comes off here as well.
                ->withoutGlobalScope('region')
                ->join('payment_entries', 'payment_entries.id', '=', 'payment_allocations.payment_entry_id')
                ->max('payment_entries.paid_at');

            if ($settledAt === null) {
                continue; // settled without money — a full credit note earns nothing
            }

            $basis = (int) $invoice->total_rupiah - (int) $invoice->ppn_rupiah;

            foreach ($credits->get($invoice->id, collect()) as $credit) {
                $basis -= (int) $credit->total_rupiah - (int) $credit->ppn_rupiah;
            }

            if ($basis <= 0) {
                continue;
            }

            $out->push([
                'invoice' => $invoice,
                'region_id' => $invoice->region_id === null ? null : (int) $invoice->region_id,
                'basis' => $basis,
                'settled_at' => Carbon::parse($settledAt),
            ]);
        }

        return $out;
    }

    /**
     * The seat kind: both seats of the customer's team, each at their rate.
     *
     * @param  Collection<int, array{invoice: Invoice, region_id: ?int, basis: int, settled_at: Carbon}>  $settled
     * @param  Collection<int, Collection<int, CommissionRate>>  $seatRates  user_id => rates
     * @return list<array<string, mixed>>
     */
    private function seatRows(Collection $settled, Collection $seatRates): array
    {
        $perSeat = collect();
        $bound = app(RegionContext::class)->regionId();

        foreach ($settled as $s) {
            // The viewer's cabang only, when they have one.
            if ($bound !== null && $s['region_id'] !== $bound) {
                continue;
            }

            $company = $s['invoice']->company;

            foreach ([
                [Role::Sales->value, $company?->salesRep],
                [Role::Marketing->value, $company?->marketingRep],
            ] as [$peran, $seat]) {
                if ($seat === null) {
                    continue;
                }

                $bp = $this->rateFor($seatRates->get($seat->id, collect()), $s['settled_at']);

                if ($bp === 0) {
                    continue;
                }

                $perSeat->push([
                    'key' => $seat->id.'|'.$peran,
                    'user' => $seat,
                    'peran' => $peran,
                    'basis' => $s['basis'],
                    // Basis points on whole rupiah, rounded per invoice.
                    'komisi' => (int) round($s['basis'] * $bp / 10_000),
                ]);
            }
        }

        $rows = [];

        foreach ($perSeat->groupBy('key') as $group) {
            $first = $group->first();
            /** @var User $user */
            $user = $first['user'];

            $rows[] = [
                'nama' => $user->name,
                'peran' => Role::from($first['peran'])->label(),
                'peran_raw' => $first['peran'],
                // Only the sales seat is targeted on the seat kind.
                'jenis_target' => $first['peran'] === Role::Sales->value ? JenisKomisi::Penjualan->value : '-',
                'user_id' => (int) $user->id,
                'faktur' => $group->count(),
                'basis' => (int) $group->sum('basis'),
                'tarif' => null,
                'komisi' => (int) $group->sum('komisi'),
                'target' => null,
                'pencapaian' => null,
            ];
        }

        return $rows;
    }

    /**
     * Supervisor, manajer, pembelian impor: one row per (person, kind,
     * cabang) that holds a rate, on its own basis.
     *
     * @param  Collection<int, array{invoice: Invoice, region_id: ?int, basis: int, settled_at: Carbon}>  $settled
     * @param  Collection<int, CommissionRate>  $rates
     * @return list<array<string, mixed>>
     */
    private function jenisRows(Period $period, Collection $settled, Collection $rates): array
    {
        $rows = [];
        $importBills = null;

        foreach ($rates->groupBy(fn (CommissionRate $r) => $r->user_id.'|'.$r->jenis.'|'.($r->cabang_id ?? '')) as $group) {
            /** @var CommissionRate $first */
            $first = $group->first();
            $user = $first->user;
            $jenis = $first->jenis();

            if ($user === null) {
                continue;
            }

            $items = match ($jenis) {
                JenisKomisi::Supervisor => $settled->filter(fn (array $s) => $s['region_id'] === (int) $first->cabang_id),
                JenisKomisi::Manajer => $settled,
                JenisKomisi::PembelianImpor => $importBills ??= $this->settledImportBills($period),
                default => collect(),
            };

            $basis = 0;
            $komisi = 0;
            $jumlah = 0;

            foreach ($items as $item) {
                $bp = $this->rateFor($group, $item['settled_at']);

                if ($bp === 0 || $item['basis'] <= 0) {
                    continue;
                }

                $jumlah++;
                $basis += $item['basis'];
                $komisi += (int) round($item['basis'] * $bp / 10_000);
            }

            $rows[] = [
                'nama' => $user->name,
                'peran' => $jenis->label().($jenis->butuhCabang() && $first->region !== null ? ' — '.$first->region->kode : ''),
                'peran_raw' => $jenis->value,
                'jenis_target' => $jenis->value,
                'user_id' => (int) $user->id,
                'faktur' => $jumlah,
                'basis' => $basis,
                'tarif' => null,
                'komisi' => $komisi,
                'target' => null,
                'pencapaian' => null,
            ];
        }

        return $rows;
    }

    /**
     * Supplier bills whose settlement finished inside the period, with the
     * import lines' worth as the basis.
     *
     * Settled the way the receivable side is settled: through the
     * allocations, on the date of the last money. A bill discharged wholly
     * by a purchase return has no money on it and correctly earns nothing;
     * one discharged partly by a return is counted at its import lines'
     * full worth — the return is the supplier's mistake, not the buyer's.
     *
     * @return Collection<int, array{basis: int, settled_at: Carbon}>
     */
    private function settledImportBills(Period $period): Collection
    {
        $bills = SupplierBill::query()
            ->withoutGlobalScope('region')
            ->where('status', SupplierBill::STATUS_PAID)
            ->where('saldo_awal', false)
            ->whereRaw(
                '(select max(spe.paid_at) from supplier_payment_allocations spa '
                .'join supplier_payment_entries spe on spe.id = spa.supplier_payment_entry_id '
                .'where spa.supplier_bill_id = supplier_bills.id) between ? and ?',
                [$period->from, $period->to],
            )
            ->get();

        $out = collect();

        foreach ($bills as $bill) {
            $settledAt = $bill->allocations()
                ->withoutGlobalScope('region')
                ->join('supplier_payment_entries', 'supplier_payment_entries.id', '=', 'supplier_payment_allocations.supplier_payment_entry_id')
                ->max('supplier_payment_entries.paid_at');

            if ($settledAt === null) {
                continue;
            }

            // Only the import lines, net of PPN: line_total_rupiah is the
            // goods amount, the bill's PPN sits beside it.
            $basis = (int) $bill->lines()
                ->join('products', 'products.kode', '=', 'supplier_bill_lines.sku')
                ->where('products.golongan', Golongan::Impor->value)
                ->sum('supplier_bill_lines.line_total_rupiah');

            if ($basis <= 0) {
                continue;
            }

            $out->push(['basis' => $basis, 'settled_at' => Carbon::parse($settledAt)]);
        }

        return $out;
    }

    /** The rate effective on a date: the latest row at or before it. */
    private function rateFor(Collection $userRates, Carbon $onDate): int
    {
        $effective = 0;

        foreach ($userRates as $rate) {
            if ($rate->berlaku_mulai->lessThanOrEqualTo($onDate)) {
                $effective = (int) $rate->basis_poin;
            }
        }

        return $effective;
    }
}
