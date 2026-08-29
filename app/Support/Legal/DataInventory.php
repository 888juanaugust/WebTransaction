<?php

declare(strict_types=1);

namespace App\Support\Legal;

/**
 * What personal data this system actually holds, why, and for how long.
 *
 * This exists because a privacy policy written as prose goes stale the first
 * time somebody adds a column. UU PDP 27/2022 Art. 21 requires the notice to
 * state the *types* of data processed, so "we may collect information about
 * you" is not a policy — it is an admission that nobody checked.
 *
 * So the notice renders from here, and DataInventoryCoversSchemaTest asserts
 * that every column of every table below is classified: either it is personal
 * data with a stated purpose and retention, or it is explicitly declared not to
 * be. Add a column holding someone's phone number and forget to say so, and the
 * build fails rather than the policy quietly becoming untrue.
 *
 * The legal bases are the ones in UU PDP Art. 20. Almost everything here is
 * `kontrak` or `kewajiban_hukum`: this is a wholesale account system, and the
 * data in it is the data required to sell goods to a business and account for
 * the tax. Very little of it rests on consent, which matters — consent can be
 * withdrawn, and a tax record cannot be deleted on request.
 */
final class DataInventory
{
    /**
     * Columns that appear on nearly every table and are never personal data on
     * their own: surrogate keys, money, quantities, and record timestamps.
     *
     * `created_at` on a *record* is not personal data; `last_login_at` on a
     * person is. The distinction is drawn per table below, not here.
     */
    private const STRUCTURAL = [
        'id', 'created_at', 'updated_at',
        /*
         * Which set of company books a row belongs to. Organisational
         * structure, not personal data: it says which branch holds the record,
         * never anything about a person. On `users` it doubles as an access
         * boundary — which region an account may see — and that is employment
         * data of the same kind as `role`, already accounted for there.
         */
        'region_id',
    ];

    /**
     * Every table that holds anything about an identifiable person.
     *
     * `personal` — the columns that are, or can identify, personal data.
     * `bukan`    — columns on the same table that are not.
     *
     * @return array<string, array{kategori: string, personal: list<string>, bukan: list<string>}>
     */
    public static function tables(): array
    {
        return [
            /*
             * Staff logins. Employment data, held because someone has to be
             * accountable for every price override and every payment confirmed.
             */
            'users' => [
                'kategori' => 'identitas_staf',
                'personal' => ['name', 'email', 'email_verified_at', 'password', 'remember_token'],
                'bukan' => ['role', 'is_active'],
            ],

            /*
             * Buyer logins. One person at a customer company, not the company.
             */
            'customer_users' => [
                'kategori' => 'identitas_pembeli',
                'personal' => ['name', 'email', 'telepon', 'password', 'remember_token', 'last_login_at'],
                'bukan' => ['company_id', 'is_active', 'created_by'],
            ],

            /*
             * The customer company itself. Mostly corporate data — which UU PDP
             * does not cover — with two honest exceptions worth spelling out in
             * the notice rather than hiding behind "business data":
             *
             *   - `nama_kontak` is a named human being.
             *   - for a customer trading as a sole proprietorship, the NPWP,
             *     the nama wajib pajak and the tax address are that person's,
             *     not a legal entity's.
             */
            'companies' => [
                'kategori' => 'identitas_pelanggan',
                'personal' => [
                    'nama', 'nama_kontak', 'telepon', 'email',
                    'npwp', 'nama_wajib_pajak', 'alamat_pajak', 'alamat_kirim', 'kota',
                    'catatan',
                ],
                'bukan' => [
                    'kode', 'jenis_usaha', 'price_tier_id', 'credit_limit_rupiah',
                    'payment_terms_days', 'status', 'approved_at', 'approved_by',
                    // The team in charge — staff references, like approved_by.
                    'sales_user_id', 'marketing_user_id',
                ],
            ],

            /*
             * Who placed and handled each order. Personal in the sense that
             * matters: it links a named person to a commercial act.
             */
            'orders' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['created_by', 'sales_user_id', 'placed_by_customer_user_id', 'catatan'],
                'bukan' => [
                    'nomor', 'company_id', 'warehouse_id', 'status', 'po_pelanggan',
                    'subtotal_rupiah', 'discount_rupiah', 'dpp_rupiah', 'ppn_rupiah',
                    'total_rupiah', 'price_list_version_id', 'submitted_at',
                    'confirmed_at', 'paid_at', 'shipped_at', 'completed_at',
                    'reservation_expires_at', 'split_parent_id',
                ],
            ],

            'order_events' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['actor_id', 'customer_actor_id', 'alasan', 'meta'],
                'bukan' => ['order_id', 'from_status', 'to_status'],
            ],

            /*
             * The audit log, and the one most policies forget: it records an IP
             * address. An IP is an identifier under UU PDP, so it is named in
             * the notice rather than filed under "technical data".
             */
            'audit_logs' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['actor_id', 'actor_role', 'ip_address', 'old_value', 'new_value', 'alasan'],
                'bukan' => ['action', 'subject_type', 'subject_id'],
            ],

            /*
             * Staff statements that a launch step outside this system was done.
             * `catatan` is where the evidence goes, and evidence names people —
             * the lawyer who read the terms, the officer who issued a PB-UMKU.
             */
            'launch_attestations' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['attested_by', 'catatan'],
                'bukan' => ['kunci', 'attested_at'],
            ],

            /*
             * Tax identity, snapshotted onto the invoice at issue. Kept for as
             * long as tax law requires the books to be kept, which is longer
             * than the customer relationship and is not deletable on request.
             */
            'invoices' => [
                'kategori' => 'pajak_dan_pembayaran',
                'personal' => ['npwp', 'nama_wajib_pajak', 'alamat_pajak'],
                'bukan' => [
                    'nomor', 'order_id', 'company_id', 'subtotal_rupiah', 'discount_rupiah',
                    'dpp_rupiah', 'ppn_rupiah', 'total_rupiah', 'issued_on', 'due_date',
                    'status', 'kode_transaksi', 'nsfp', 'faktur_exported_at',
                    'debt_notified_at',
                ],
            ],

            'payment_entries' => [
                'kategori' => 'pajak_dan_pembayaran',
                'personal' => ['actor_id', 'catatan'],
                'bukan' => [
                    'company_id', 'invoice_id', 'order_id', 'amount_rupiah', 'kind',
                    'reverses_entry_id', 'paid_at',
                ],
            ],

            'store_visits' => [
                'kategori' => 'pajak_dan_pembayaran',
                /*
                 * Coordinates and a photo taken at a customer's door are
                 * personal data twice over — the sales' movements and the
                 * store's premises — which is why the photo carries a
                 * 2-month retention and the purge is audited.
                 */
                'personal' => ['sales_user_id', 'latitude', 'longitude', 'foto_path', 'catatan'],
                'bukan' => ['company_id', 'visited_at', 'foto_dihapus_pada'],
            ],

            'sales_expense_claims' => [
                'kategori' => 'pajak_dan_pembayaran',
                'personal' => ['sales_user_id', 'decided_by', 'keterangan', 'keputusan_catatan'],
                'bukan' => ['tanggal', 'amount_rupiah', 'status', 'decided_at', 'expense_id'],
            ],

            'debt_removals' => [
                'kategori' => 'pajak_dan_pembayaran',
                // Who claimed the money and who verified it are the two names
                // the whole control rests on; the free-text fields describe a
                // cash handover and can name people and places.
                'personal' => ['initiated_by', 'decided_by', 'alasan', 'keputusan_catatan'],
                'bukan' => [
                    'invoice_id', 'company_id', 'amount_rupiah', 'status',
                    'decided_at', 'payment_entry_id',
                ],
            ],

            'carts' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['customer_user_id'],
                'bukan' => ['company_id', 'warehouse_id'],
            ],

            /*
             * Who uploaded which supplier price file. The files themselves are
             * commercial data, not personal — but the uploader is a person.
             */
            'price_list_imports' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['uploaded_by', 'approved_by', 'note'],
                'bukan' => [
                    'original_filename', 'stored_path', 'checksum', 'status',
                    'is_full_replacement', 'effective_from', 'row_count', 'blocker_count',
                    'note_count', 'diff', 'parse_error', 'price_list_version_id',
                    'approved_at', 'brake_acknowledgement',
                ],
            ],

            /*
             * Suppliers are businesses, but the named contact is a person, and
             * a supplier trading as a sole proprietorship has a personal NPWP —
             * the same distinction drawn for customers above.
             */
            'suppliers' => [
                'kategori' => 'identitas_pemasok',
                'personal' => [
                    'nama', 'nama_kontak', 'telepon', 'email', 'alamat', 'npwp', 'catatan',
                ],
                'bukan' => ['kode', 'aktif', 'payment_terms_days'],
            ],

            'goods_receipts' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['created_by', 'posted_by', 'catatan'],
                'bukan' => [
                    'nomor', 'supplier_id', 'purchase_order_id', 'warehouse_id', 'status',
                    'nomor_surat_jalan_supplier', 'nomor_faktur_supplier',
                    'tanggal_terima', 'total_value_rupiah', 'posted_at',
                ],
            ],

            'purchase_orders' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['created_by', 'sent_by', 'closed_by', 'catatan', 'alasan_batal'],
                'bukan' => [
                    'nomor', 'supplier_id', 'warehouse_id', 'status', 'tanggal_po',
                    'tanggal_diharapkan', 'referensi_supplier', 'total_value_rupiah',
                    'sent_at', 'closed_at',
                ],
            ],

            /*
             * A supplier's faktur pajak number identifies their tax document,
             * and for a supplier trading as a sole proprietorship that document
             * is a person's — the same distinction drawn for customers.
             */
            'supplier_bills' => [
                'kategori' => 'pajak_dan_pembayaran',
                'personal' => [
                    'nomor_faktur_supplier', 'nomor_faktur_pajak',
                    'created_by', 'posted_by', 'catatan',
                ],
                'bukan' => [
                    'nomor', 'supplier_id', 'purchase_order_id', 'tanggal_faktur',
                    'due_date', 'status', 'subtotal_rupiah', 'discount_rupiah',
                    'dpp_rupiah', 'ppn_rupiah', 'total_rupiah', 'kode_transaksi', 'posted_at',
                ],
            ],

            'supplier_payment_entries' => [
                'kategori' => 'pajak_dan_pembayaran',
                'personal' => ['actor_id', 'referensi', 'catatan'],
                'bukan' => [
                    'supplier_id', 'supplier_bill_id', 'amount_rupiah', 'kind',
                    'reverses_entry_id', 'paid_at',
                ],
            ],

            /*
             * Stock counts. `catatan` is where somebody writes what they think
             * happened to the missing cartons, which in practice names people.
             * Counted by one person and approved by another, and both are
             * recorded because that separation is the control.
             */
            'stock_opnames' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'created_by', 'counted_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'warehouse_id', 'tanggal', 'status',
                    'selisih_qty', 'selisih_rupiah', 'posted_at',
                ],
            ],

            'stock_opname_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan'],
                'bukan' => [
                    'stock_opname_id', 'sku', 'urutan', 'qty_system', 'qty_counted',
                    'selisih_qty', 'unit_cost_rupiah', 'selisih_rupiah',
                ],
            ],

            /*
             * Transfers between our own warehouses. Goods and quantities, plus
             * whoever moved them.
             */
            'stock_transfers' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'created_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'from_warehouse_id', 'to_warehouse_id', 'tanggal',
                    'status', 'total_value_rupiah', 'posted_at',
                ],
            ],

            'stock_transfer_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan'],
                'bukan' => [
                    'stock_transfer_id', 'sku', 'urutan', 'ordered_unit', 'ordered_qty',
                    'qty_per_ctn_snapshot', 'qty_base', 'unit_cost_rupiah', 'line_value_rupiah',
                ],
            ],

            /*
             * Faktur pajak filings. About tax periods and totals rather than
             * people, apart from who filed each one.
             *
             * The buyers' NPWP and tax addresses are *in the file* this record
             * points at, not in these columns — the file is a report of what
             * `invoices` already holds, and that table is classified above.
             * Worth knowing when answering an access request: the exports on
             * disk carry the same personal data as the invoices behind them.
             */
            'faktur_exports' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'created_by'],
                'bukan' => [
                    'nomor', 'masa_pajak', 'tahun_pajak', 'format', 'jumlah_faktur',
                    'total_dpp_rupiah', 'total_ppn_rupiah', 'file_path',
                ],
            ],

            'faktur_export_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => [],
                'bukan' => [
                    'faktur_export_id', 'invoice_id', 'referensi', 'nsfp', 'nsfp_recorded_at',
                ],
            ],

            /*
             * Landed cost allocations — freight and duty being spread over the
             * goods they belong to. About shipments and suppliers rather than
             * people, apart from who drew it up and who posted it.
             */
            'landed_costs' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'created_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'supplier_bill_line_id', 'tanggal', 'dasar', 'amount_rupiah',
                    'status', 'ke_persediaan_rupiah', 'ke_hpp_rupiah', 'posted_at',
                ],
            ],

            'landed_cost_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => [],
                'bukan' => [
                    'landed_cost_id', 'goods_receipt_line_id', 'sku', 'urutan', 'warehouse_id',
                    'dasar_nilai', 'qty_base', 'amount_rupiah', 'qty_on_hand',
                    'ke_persediaan_rupiah', 'ke_hpp_rupiah',
                ],
            ],

            /*
             * Bilyet giro. A negotiable instrument names its issuing bank and
             * carries a number that identifies the account it is drawn on,
             * which for a CV or a sole trader is effectively the owner's own
             * bank account. `alasan_selesai` records why one bounced, which is
             * a statement about somebody's finances in the bank's words.
             */
            'giros' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => [
                    'bank_penerbit', 'nomor_warkat', 'alasan_selesai', 'catatan',
                    'created_by', 'resolved_by',
                ],
                'bukan' => [
                    'nomor', 'arah', 'company_id', 'supplier_id', 'invoice_id',
                    'supplier_bill_id', 'nilai_rupiah', 'tanggal_terima',
                    'tanggal_jatuh_tempo', 'tanggal_setor', 'status', 'tanggal_selesai',
                    'payment_entry_id', 'supplier_payment_entry_id',
                ],
            ],

            /*
             * Supplier credit notes. `alasan` is mandatory free text about a
             * commercial dispute, and free text on a dispute names people the
             * same way a customer credit note's does — who agreed the price,
             * who rang about it.
             */
            'supplier_credit_notes' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['alasan', 'catatan', 'created_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'supplier_id', 'supplier_bill_id', 'tanggal',
                    'nomor_nota_supplier', 'account_id', 'dasar_rupiah', 'ppn_rupiah',
                    'ada_faktur_pajak_retur', 'total_rupiah', 'status', 'posted_at',
                ],
            ],

            /*
             * A deposit is a customer's money, so the row is about them by
             * definition — but the columns that identify a person are the same
             * ones as anywhere else. `referensi` carries their transfer
             * reference, which on a personal account can be a name.
             */
            'customer_deposits' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['referensi', 'catatan', 'created_by'],
                'bukan' => [
                    'nomor', 'company_id', 'order_id', 'tanggal', 'jumlah_rupiah',
                    'diterima_di', 'terpakai_rupiah', 'dikembalikan_rupiah', 'status',
                ],
            ],

            'customer_deposit_movements' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'actor_id'],
                'bukan' => [
                    'customer_deposit_id', 'jenis', 'jumlah_rupiah', 'invoice_id',
                    'payment_entry_id', 'tanggal',
                ],
            ],

            /*
             * Fixed assets. Mostly corporate property, with one honest
             * exception: `nama` on a vehicle is usually its registration
             * plate, which identifies a vehicle and through it a company —
             * and for a sole trader, a person.
             */
            'fixed_assets' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['nama', 'keterangan', 'alasan_pelepasan', 'created_by', 'disposed_by'],
                'bukan' => [
                    'nomor', 'kelompok', 'masa_manfaat_bulan', 'tanggal_perolehan',
                    'harga_perolehan_rupiah', 'nilai_residu_rupiah', 'kategori', 'status',
                    'dibayar_dari', 'tanggal_pelepasan', 'harga_jual_rupiah',
                ],
            ],

            // A month's depreciation is arithmetic about a thing, not a person.
            'fixed_asset_depreciations' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['created_by'],
                'bukan' => [
                    'fixed_asset_id', 'periode', 'tanggal', 'amount_rupiah',
                    'nilai_buku_setelah_rupiah', 'journal_entry_id',
                ],
            ],

            /*
             * Expenses. `keterangan` and `referensi` are free text about a
             * payment, and both routinely name people — "gaji Budi Agustus",
             * "sewa ruko Bu Sri". Salary lines in particular make this table
             * more sensitive than its size suggests.
             */
            'expenses' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['keterangan', 'referensi', 'catatan', 'created_by'],
                'bukan' => [
                    'nomor', 'tanggal', 'account_id', 'dibayar_dari', 'amount_rupiah',
                    'supplier_id', 'reverses_expense_id',
                ],
            ],

            /*
             * Bank reconciliations. The header holds no personal data beyond
             * who did it — the figures are our own balances and one number
             * typed off a statement.
             */
            'bank_reconciliations' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['catatan', 'created_by', 'finalised_by'],
                'bukan' => [
                    'nomor', 'tanggal_rekening', 'saldo_rekening_rupiah', 'saldo_buku_rupiah',
                    'setoran_beredar_rupiah', 'penarikan_beredar_rupiah', 'selisih_rupiah',
                    'status', 'finalised_at',
                ],
            ],

            // A tick is two foreign keys. It says nothing about anybody.
            'bank_reconciliation_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => [],
                'bukan' => ['bank_reconciliation_id', 'journal_line_id'],
            ],

            /*
             * Items off the statement. `keterangan` is free text copied from a
             * bank statement line, and a bank's own wording routinely names the
             * counterparty — "TRF DR CV SINAR", "BIAYA RTGS KE BUDI S". Typing
             * it in verbatim is the point, so it is treated as personal.
             */
            'bank_reconciliation_items' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['keterangan', 'created_by'],
                'bukan' => [
                    'bank_reconciliation_id', 'tanggal', 'account_id', 'arah',
                    'amount_rupiah', 'journal_entry_id',
                ],
            ],

            /*
             * Purchase returns — goods going back to a supplier. `alasan` is
             * mandatory free text saying why, and free text on a dispute names
             * people the same way a credit note's does: who found the fault,
             * who agreed to take it back, which driver brought the wrong pallet.
             */
            'purchase_returns' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['alasan', 'created_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'supplier_id', 'goods_receipt_id', 'warehouse_id', 'tanggal',
                    'nomor_nota_kredit_supplier', 'nilai_ditagih_rupiah',
                    'nilai_belum_ditagih_rupiah', 'dpp_rupiah', 'ppn_rupiah',
                    'total_rupiah', 'nilai_persediaan_rupiah', 'selisih_rupiah',
                    'kode_transaksi', 'status', 'posted_at',
                ],
            ],

            'purchase_return_lines' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => [],
                'bukan' => [
                    'purchase_return_id', 'goods_receipt_line_id', 'supplier_bill_id',
                    'sku', 'urutan', 'deskripsi', 'ordered_unit', 'ordered_qty',
                    'qty_per_ctn_snapshot', 'qty_base', 'qty_ditagih',
                    'nilai_ditagih_rupiah', 'nilai_belum_ditagih_rupiah',
                    'dpp_rupiah', 'ppn_rupiah', 'unit_cost_rupiah', 'nilai_persediaan_rupiah',
                ],
            ],

            /*
             * Credit notes. `alasan` is mandatory free text explaining why a
             * customer is getting money back, which in practice names people:
             * who complained, who agreed to it, which driver damaged what.
             */
            'credit_notes' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['alasan', 'created_by', 'posted_by'],
                'bukan' => [
                    'nomor', 'invoice_id', 'company_id', 'jenis', 'tanggal',
                    'warehouse_id', 'subtotal_rupiah', 'dpp_rupiah', 'ppn_rupiah',
                    'total_rupiah', 'hpp_rupiah', 'kode_transaksi',
                    'nomor_nota_retur', 'status', 'posted_at',
                ],
            ],

            /*
             * Closing and reopening the books. Both name the member of staff
             * who did it, and a reopening carries their stated reason in free
             * text — which is exactly the sort of field that ends up
             * mentioning a customer or a colleague by name.
             */
            'accounting_periods' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['closed_by', 'catatan'],
                'bukan' => ['tahun', 'bulan', 'closed_at', 'closing_entry_id'],
            ],

            'accounting_period_reopenings' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['reopened_by', 'alasan'],
                'bukan' => ['tahun', 'bulan', 'reversal_entry_id'],
            ],

            /*
             * The general ledger. An entry names the member of staff who posted
             * it, and its description can carry a customer or supplier name in
             * free text. The lines below it carry only figures — the people are
             * on the entry, and on the company_id/supplier_id keys that point
             * back at rows already described elsewhere.
             */
            'journal_entries' => [
                'kategori' => 'aktivitas_transaksi',
                'personal' => ['posted_by', 'keterangan'],
                'bukan' => [
                    'nomor', 'tanggal', 'source_type', 'source_id', 'jenis',
                    'total_debit_rupiah', 'total_kredit_rupiah', 'posted_at',
                    'reversed_by_entry_id', 'reverses_entry_id',
                ],
            ],
        ];
    }

    /**
     * Tables that hold nothing about an identifiable person.
     *
     * This list is not documentation — it is the other half of the check. The
     * schema test asserts that every table in the database appears either here
     * or in tables() above, so creating a table and forgetting to classify it
     * fails the build.
     *
     * That gap was real: the first version of this class only walked the tables
     * it already knew about, so four new tables arrived completely unexamined.
     * A guard that only checks what it was already told about is not a guard.
     *
     * @return list<string>
     */
    public static function tablesWithoutPersonalData(): array
    {
        return [
            /*
             * Company structure. A region's address and phone number are the
             * branch's, not a person's.
             */
            'regions',

            /*
             * Panel notifications — reminders to staff about aging debt.
             * notifiable_id points at a staff account and the payload names a
             * customer, but both are already declared on their own tables;
             * this table is a delivery mechanism with a read receipt.
             */
            'notifications',

            // Catalogue and commercial reference data.
            'products', 'warehouses', 'price_tiers', 'price_tier_items',
            'price_list_versions', 'price_list_items', 'company_price_overrides',
            'price_list_import_rows',

            // Stock and costing: quantities and money about goods, not people.
            'stock_levels', 'stock_movements', 'stock_reservations', 'product_costs',
            'goods_receipt_lines', 'purchase_order_lines', 'supplier_bill_lines',

            /*
             * Accounting. The chart of accounts is reference data — account
             * names and codes, nothing about anybody. Journal lines are two
             * figures and a memo about the movement, with the person on the
             * parent entry.
             */
            'accounts', 'journal_lines',

            // Credit note detail: quantities and money about goods. The person
            // and the reason are on the parent note.
            'credit_note_lines',

            // Order and cart detail. The people are on the parent rows.
            'order_lines', 'cart_items',

            // Plumbing.
            'document_counters', 'migrations', 'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs',

            /*
             * Backup runs: timestamps, byte counts and a destination path.
             * Nothing about a person is in this table.
             *
             * The **files it points at** are another matter entirely — a
             * database dump is every customer record there is. That is worth
             * being explicit about when answering a deletion request under UU
             * PDP: erasing somebody from the live database does not erase them
             * from last month's backups, and it should not, because a backup
             * that can be edited is not evidence of anything. They age out
             * instead, on the retention window in config/backup.php, which is
             * the honest answer to give.
             */
            'backup_runs',

            /*
             * Framework tables that do hold personal data but are not ours to
             * describe row by row: `sessions` carries an IP address and user
             * agent, and `password_reset_tokens` an email address. Both are
             * covered by the notice under activity and account data, and both
             * are short-lived by construction.
             */
            'sessions', 'password_reset_tokens',
        ];
    }

    /**
     * The categories as the notice presents them, in the order a reader needs.
     *
     * `dasar` values are the legal bases of UU PDP Art. 20; `retensi` states how
     * long, and why that long — a retention period without a reason is a number
     * somebody made up.
     *
     * @return list<array{kunci: string, judul: string, isi: string, dasar: string, tujuan: string, retensi: string}>
     */
    public static function categories(): array
    {
        return [
            [
                'kunci' => 'identitas_pelanggan',
                'judul' => 'Identitas perusahaan pelanggan dan narahubungnya',
                'isi' => 'Nama badan usaha, alamat pengiriman dan alamat pajak, kota, nomor '
                    .'telepon, alamat email, NPWP dan nama wajib pajak, nama narahubung, serta '
                    .'catatan internal mengenai akun.',
                'dasar' => 'Pelaksanaan perjanjian jual beli dan permintaan calon pelanggan '
                    .'sebelum perjanjian dibuat (UU PDP Pasal 20 ayat 2 huruf b), serta kewajiban '
                    .'hukum perpajakan (huruf c).',
                'tujuan' => 'Memverifikasi kelayakan akun grosir, menetapkan limit kredit dan '
                    .'termin pembayaran, mengirim barang ke alamat yang benar, dan menerbitkan '
                    .'faktur dengan identitas pajak yang benar.',
                'retensi' => 'Selama akun aktif, lalu 10 tahun sejak transaksi terakhir mengikuti '
                    .'kewajiban penyimpanan dokumen perusahaan dan pembukuan pajak.',
            ],
            [
                'kunci' => 'identitas_pembeli',
                'judul' => 'Akun masuk pengguna portal pelanggan',
                'isi' => 'Nama, alamat email, nomor telepon, kata sandi yang disimpan dalam bentuk '
                    .'hash, dan waktu masuk terakhir.',
                'dasar' => 'Pelaksanaan perjanjian (UU PDP Pasal 20 ayat 2 huruf b).',
                'tujuan' => 'Memberi akses ke portal pelanggan, menampilkan harga dan tagihan yang '
                    .'benar untuk perusahaan yang bersangkutan, dan mengamankan akun.',
                'retensi' => 'Dihapus dalam 30 hari setelah akun dinonaktifkan atau atas permintaan '
                    .'pelanggan. Jejak transaksi yang sudah terjadi tetap tersimpan sesuai '
                    .'kewajiban pembukuan.',
            ],
            [
                'kunci' => 'identitas_staf',
                'judul' => 'Akun masuk staf kami',
                'isi' => 'Nama, alamat email, dan kata sandi yang disimpan dalam bentuk hash.',
                'dasar' => 'Pelaksanaan hubungan kerja dan kepentingan sah kami untuk mengamankan '
                    .'sistem (UU PDP Pasal 20 ayat 2 huruf b dan huruf f).',
                'tujuan' => 'Memberi akses sesuai peran, dan memastikan setiap tindakan yang '
                    .'menyangkut uang dapat dipertanggungjawabkan kepada orang tertentu.',
                'retensi' => 'Dihapus dalam 30 hari setelah hubungan kerja berakhir, kecuali jejak '
                    .'audit yang wajib disimpan.',
            ],
            [
                'kunci' => 'identitas_pemasok',
                'judul' => 'Identitas pemasok dan narahubungnya',
                'isi' => 'Nama badan usaha pemasok, alamat, nomor telepon, alamat email, NPWP, '
                    .'nama narahubung, serta catatan internal mengenai pemasok tersebut.',
                'dasar' => 'Pelaksanaan perjanjian pembelian dan kewajiban hukum perpajakan '
                    .'(UU PDP Pasal 20 ayat 2 huruf b dan huruf c).',
                'tujuan' => 'Mencatat asal barang yang kami terima, mencocokkan penerimaan barang '
                    .'dengan faktur pemasok, dan memenuhi kewajiban pembukuan.',
                'retensi' => '10 tahun sejak transaksi terakhir, mengikuti kewajiban penyimpanan '
                    .'dokumen perusahaan dan pembukuan pajak.',
            ],
            [
                'kunci' => 'aktivitas_transaksi',
                'judul' => 'Catatan aktivitas dan jejak audit',
                'isi' => 'Siapa membuat, menyetujui, menolak, mengirim dan menyelesaikan setiap '
                    .'pesanan, beserta waktunya; perubahan harga dan limit kredit berikut nilai '
                    .'lama dan barunya; alasan yang diketik; isi keranjang belanja; serta '
                    .'<strong>alamat IP</strong> yang tercatat pada jejak audit.',
                'dasar' => 'Kewajiban hukum penyelenggaraan pembukuan, dan kepentingan sah kami '
                    .'untuk mencegah dan menelusuri penyalahgunaan (UU PDP Pasal 20 ayat 2 huruf c '
                    .'dan huruf f).',
                'tujuan' => 'Memastikan setiap perubahan yang berdampak pada uang dapat '
                    .'ditelusuri, menyelesaikan sengketa mengenai pesanan, dan mendeteksi akses '
                    .'yang tidak sah.',
                'retensi' => '10 tahun, mengikuti kewajiban penyimpanan pembukuan. Jejak audit '
                    .'bersifat append-only dan tidak dapat diubah, termasuk oleh kami.',
            ],
            [
                'kunci' => 'pajak_dan_pembayaran',
                'judul' => 'Data pembayaran dan perpajakan',
                'isi' => 'Jumlah dan waktu pembayaran, catatan pencocokan yang dibuat tim '
                    .'keuangan kami saat mengonfirmasi transfer, tunai, atau giro Anda, dan '
                    .'identitas pajak yang disalin ke faktur. Termasuk pula pembayaran yang kami '
                    .'lakukan kepada pemasok beserta referensi transfernya, dan nomor faktur '
                    .'pajak pemasok yang menjadi dasar pengkreditan pajak masukan.',
                'dasar' => 'Pelaksanaan perjanjian dan kewajiban hukum perpajakan (UU PDP Pasal 20 '
                    .'ayat 2 huruf b dan huruf c).',
                'tujuan' => 'Mencocokkan pembayaran dengan tagihan, mencegah pembayaran tercatat '
                    .'dua kali, menerbitkan faktur pajak, membayar pemasok, dan memenuhi '
                    .'kewajiban pelaporan pajak keluaran maupun masukan.',
                'retensi' => '10 tahun sejak akhir tahun pajak yang bersangkutan. Callback mentah '
                    .'disimpan karena menjadi bukti asal setiap pembayaran yang tercatat.',
            ],
        ];
    }

    /**
     * The columns of one table that this inventory accounts for.
     *
     * @return list<string>
     */
    public static function accountedColumns(string $table): array
    {
        $entry = self::tables()[$table] ?? ['personal' => [], 'bukan' => []];

        return array_values(array_unique([
            ...self::STRUCTURAL,
            ...$entry['personal'],
            ...$entry['bukan'],
        ]));
    }

    /**
     * The tables holding personal data, grouped under the category that
     * describes them in the notice.
     *
     * @return array<string, list<string>>
     */
    public static function tablesByCategory(): array
    {
        $grouped = [];

        foreach (self::tables() as $table => $entry) {
            $grouped[$entry['kategori']][] = $table;
        }

        return $grouped;
    }
}
