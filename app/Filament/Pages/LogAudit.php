<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Role;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who did what, and what it used to be.
 *
 * The project's own convention is that every money-affecting action writes
 * here — price overrides, credit-limit overrides, payment reversals, a month
 * reopened after it was closed. All of it was being written for an audience
 * that had no way to read it: the role matrix promises the owner an audit log
 * and there was no screen anywhere. A log nobody can open is a log nobody
 * checks, which is the same as not keeping one, except slower.
 *
 * Read-only, and structurally so. There is no create, edit or delete action
 * here and no resource behind it — an audit trail somebody can tidy is
 * evidence of nothing.
 *
 * Owner only, matching `canViewAuditLog()`. It carries the old and new value
 * of everything anybody overrode, which includes prices and credit limits,
 * so it is the one screen that leaks every kind of data the role separation
 * elsewhere is built to keep apart.
 */
class LogAudit extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static \UnitEnum|string|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Log audit';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'log-audit';

    protected string $view = 'filament.pages.log-audit';

    public function getTitle(): string
    {
        return 'Log audit';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canViewAuditLog() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->with('actor')->latest('created_at')->latest('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('actor.name')
                    ->label('Oleh')
                    /*
                     * The scheduler and invoice settlement act with nobody
                     * behind them. "Sistem" is truer than an empty cell, which
                     * reads as data somebody failed to record.
                     */
                    ->placeholder('Sistem')
                    /*
                     * Suppressed when it would repeat the name. The seeded
                     * accounts are literally called "Keuangan", and a column
                     * reading "Keuangan / Keuangan" looks like a rendering
                     * fault rather than a role.
                     */
                    ->description(function (AuditLog $r) {
                        $role = $r->actor_role ? Role::tryFrom($r->actor_role)?->label() : null;

                        return $role === $r->actor?->name ? null : $role;
                    })
                    ->searchable(),

                TextColumn::make('action')
                    ->label('Tindakan')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::actionLabel($state))
                    ->color(fn (string $state) => static::isReversal($state) ? 'danger' : 'gray')
                    // The subject rides underneath rather than taking a column
                    // of its own: seven columns pushed Perubahan — the whole
                    // substance of an audit log — off the right edge.
                    ->description(fn (AuditLog $r) => $r->subject_type === null
                        ? null
                        : class_basename($r->subject_type).' #'.$r->subject_id)
                    ->searchable(),

                TextColumn::make('alasan')
                    ->label('Alasan')
                    ->limit(40)
                    ->tooltip(fn (AuditLog $r) => strlen((string) $r->alasan) > 40 ? $r->alasan : null)
                    /*
                     * The column that matters most on an override, and the one
                     * most often empty. Saying so is the point: an override
                     * with no stated reason is exactly what an auditor asks
                     * about.
                     */
                    ->placeholder('— tidak disebutkan'),

                /*
                 * The reason anybody opens this screen, so it gets the room.
                 * Wrapped rather than truncated: a change summary cut at forty
                 * characters usually loses the value that changed.
                 */
                TextColumn::make('perubahan')
                    ->label('Perubahan')
                    ->state(fn (AuditLog $r) => static::changeSummary($r))
                    ->wrap()
                    ->size('xs')
                    ->placeholder('—'),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Tindakan')
                    ->options(fn () => static::actionOptions())
                    ->searchable(),

                SelectFilter::make('actor_id')
                    ->label('Oleh')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                Filter::make('periode')
                    ->schema([
                        DatePicker::make('dari')->label('Dari')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('sampai')->label('Sampai')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),

                /*
                 * The filter somebody actually reaches for. "Show me every
                 * time a price or a credit limit was overruled" is the
                 * question an audit log exists to answer, and finding those
                 * among thousands of routine postings is otherwise a scroll.
                 */
                Filter::make('hanya_penting')
                    ->label('Hanya penimpaan dan pembalikan')
                    ->query(fn (Builder $query) => $query->whereIn('action', static::sensitiveActions())),
            ])
            ->emptyStateHeading('Belum ada yang tercatat')
            ->emptyStateDescription('Setiap tindakan yang menyangkut uang tercatat di sini dengan sendirinya.')
            ->defaultPaginationPageOption(25);
    }

    /**
     * Overrides and reversals — the rows an auditor came for.
     *
     * @return list<string>
     */
    public static function sensitiveActions(): array
    {
        return [
            'price_override',
            'credit_limit_override',
            'payment_reversed',
            'supplier_payment_reversed',
            'expense_reversed',
            'journal_reversed',
            'journal_posted_manually',
            'accounting_period_reopened',
            'purchase_order_cancelled',
            'launch_item_retracted',
            'company_status_changed',
            // A debt disappearing and an order disappearing are exactly the
            // rows an audit reads this log for.
            'debt_removal_approved',
            'order_erased',
        ];
    }

    public static function isReversal(string $action): bool
    {
        return in_array($action, static::sensitiveActions(), true);
    }

    /**
     * A readable name, falling back to the raw key.
     *
     * The fallback is deliberate: a new action added tomorrow appears as its
     * own key rather than vanishing from a filter nobody updated.
     */
    public static function actionLabel(string $action): string
    {
        return static::labels()[$action] ?? str_replace('_', ' ', $action);
    }

    /** @return array<string, string> */
    public static function actionOptions(): array
    {
        $used = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return $used
            ->mapWithKeys(fn (string $a) => [$a => static::actionLabel($a)])
            ->all();
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'price_override' => 'Harga ditimpa',
            'credit_limit_override' => 'Limit kredit ditimpa',
            'order_transition' => 'Order berpindah status',
            'order_priced' => 'Order dihargai',
            'invoice_issued' => 'Faktur terbit',
            'payment_confirmed' => 'Pembayaran dikonfirmasi',
            'payment_allocated' => 'Pembayaran dicocokkan',
            'payment_reversed' => 'Pembayaran dibalik',
            'supplier_payment_recorded' => 'Pembayaran pemasok',
            'supplier_payment_reversed' => 'Pembayaran pemasok dibalik',
            'credit_note_posted' => 'Nota kredit diposting',
            'goods_receipt_posted' => 'Penerimaan barang diposting',
            'supplier_bill_posted' => 'Tagihan pemasok diposting',
            'purchase_order_sent' => 'PO dikirim',
            'purchase_order_cancelled' => 'PO dibatalkan',
            'purchase_order_closed' => 'PO ditutup',
            'purchase_order_drafted_from_reorder' => 'Draf PO dari titik pesan ulang',
            'purchase_return_posted' => 'Retur pembelian diposting',
            'supplier_credit_note_drafted' => 'Nota kredit pemasok didraf',
            'supplier_credit_note_posted' => 'Nota kredit pemasok diposting',
            'supplier_credit_note_discarded' => 'Draf nota kredit pemasok dibuang',
            'customer_deposit_received' => 'Uang muka diterima',
            'customer_deposit_applied' => 'Uang muka dipakai',
            'customer_deposit_refunded' => 'Uang muka dikembalikan',
            'stock_transfer_posted' => 'Transfer gudang diposting',
            'stock_opname_posted' => 'Stok opname diposting',
            'landed_cost_posted' => 'Biaya perolehan dialokasikan',
            'expense_recorded' => 'Beban dicatat',
            'expense_reversed' => 'Beban dibalik',
            'fixed_asset_acquired' => 'Aktiva tetap dibeli',
            'fixed_asset_disposed' => 'Aktiva tetap dilepas',
            'depreciation_run' => 'Penyusutan dijalankan',
            'journal_posted_manually' => 'Jurnal manual',
            'journal_reversed' => 'Jurnal dibalik',
            'accounting_period_closed' => 'Periode ditutup',
            'accounting_period_reopened' => 'Periode dibuka kembali',
            'bank_reconciliation_opened' => 'Rekonsiliasi dibuka',
            'bank_reconciliation_finalised' => 'Rekonsiliasi diselesaikan',
            'bank_statement_item_recorded' => 'Item rekening koran dicatat',
            'price_list_published' => 'Daftar harga diterbitkan',
            'faktur_exported' => 'Faktur pajak diekspor',
            'nsfp_recorded' => 'NSFP dicatat',
            'company_status_changed' => 'Status pelanggan diubah',
            'debt_removal_initiated' => 'Pelunasan piutang diajukan',
            'debt_removal_approved' => 'Pelunasan piutang disetujui',
            'debt_removal_rejected' => 'Pelunasan piutang ditolak',
            'order_erased' => 'Order belum jadi dihapus',
            'sales_expense_claimed' => 'Biaya ekspedisi diajukan',
            'sales_expense_approved' => 'Biaya ekspedisi disetujui',
            'sales_expense_rejected' => 'Biaya ekspedisi ditolak',
            'visit_photos_purged' => 'Foto kunjungan kedaluwarsa dihapus',
            'customer_password_reset' => 'Sandi pembeli direset sendiri',
            'pengaturan_diubah' => 'Pengaturan perusahaan diubah',
            'visits_archived' => 'Kunjungan diarsipkan',
            'order_split' => 'Order dipecah per gudang',
            'customer_portal_access_granted' => 'Akses portal diberikan',
            'customer_portal_invite_sent' => 'Undangan portal dikirim',
            /*
             * Giro. `giro_cair`, `giro_ditolak` and `giro_dibatalkan` are
             * written as 'giro_'.$status->value from the shared release path,
             * so a new GiroStatus case needs a line here too — GiroStatus
             * itself is the list to check against.
             */
            'giro_deposited' => 'Giro disetor',
            'giro_cleared' => 'Giro cair',
            'giro_cair' => 'Giro cair',
            'giro_ditolak' => 'Giro ditolak',
            'giro_dibatalkan' => 'Giro dikembalikan',
            'giro_beredar' => 'Giro beredar',

            'launch_item_attested' => 'Item peluncuran dinyatakan',
            'launch_item_retracted' => 'Pernyataan peluncuran dicabut',

            /*
             * Staff accounts. Not money, and the only entries here that are
             * not — they are in the log because the role matrix is this
             * system's internal control, and these five are the only way an
             * assignment under it moves. "Who gave Keuangan the Sales role"
             * is the question a log answers or fails to.
             */
            'staff_created' => 'Staf ditambahkan',
            'staff_renamed' => 'Nama staf diubah',
            'staff_role_changed' => 'Peran staf diubah',
            'staff_region_changed' => 'Wilayah staf diubah',
            'company_sales_assigned' => 'Sales pelanggan ditugaskan',
            'company_marketing_assigned' => 'Marketing pelanggan ditugaskan',
            'staff_password_reset' => 'Sandi staf disetel ulang',
            'staff_deactivated' => 'Staf dinonaktifkan',
            'staff_reactivated' => 'Staf diaktifkan lagi',
        ];
    }

    /**
     * Old → new, in one line.
     *
     * Only the keys that actually changed. Printing whole payloads side by
     * side makes the one field somebody altered impossible to find, which is
     * the only reason anybody opened the row.
     */
    public static function changeSummary(AuditLog $log): ?string
    {
        $old = $log->old_value ?? [];
        $new = $log->new_value ?? [];

        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $parts = [];

        foreach ($keys as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if ($before === $after) {
                continue;
            }

            $parts[] = match (true) {
                $before === null => sprintf('%s: %s', $key, static::scalar($after)),
                $after === null => sprintf('%s: %s → —', $key, static::scalar($before)),
                default => sprintf('%s: %s → %s', $key, static::scalar($before), static::scalar($after)),
            };
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '…',
            is_int($value) || is_float($value) => number_format((float) $value, 0, ',', '.'),
            default => (string) $value,
        };
    }
}
