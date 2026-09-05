<?php

declare(strict_types=1);

namespace App\Domain\Launch;

use App\Domain\Access\Role;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Orders\OrderStatus;
use App\Models\BackupRun;
use App\Models\LaunchAttestation;
use App\Models\Order;
use App\Models\PriceListVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Is this thing ready to be used by a real business?
 *
 * The checklist in docs/DEPLOY.md is the same list, and a screen that merely
 * repeated it would be worse than the file: a tickbox nobody can verify gets
 * ticked, and then stays ticked long after it stopped being true.
 *
 * So most of this list **checks itself**, every time the page is opened. Is the
 * tax NPWP set? Has a price list been published? Does any staff account still
 * have the seeded password? Those are facts in this database, and asking a
 * person about them would be inviting a wrong answer.
 *
 * What is left over is the part nothing here can see — an OSS registration, a
 * lawyer's reading, a restore actually rehearsed on the real server. Those are
 * recorded as somebody's statement, with their name and the date against it.
 *
 * The two are deliberately not made to look alike. A check is evidence; a
 * statement is a claim. Presenting them as the same green tick would be the
 * thing this screen exists to avoid.
 */
class LaunchReadiness
{
    /**
     * Placeholder values shipped in config/perusahaan.php.
     *
     * Matched exactly rather than by "looks like an example", because a
     * heuristic that guesses would eventually call a real address fake and
     * block a launch for no reason.
     */
    private const PLACEHOLDER_CONTACT = [
        'alamat' => 'Jl. Contoh No. 1, Jakarta, Indonesia',
        'telepon' => '+62 21 0000 0000',
        'whatsapp' => '+62 800 0000 0000',
        'email' => 'sales@example.com',
    ];

    // The shipped example partners are named "Partner Name One/Two" since the
    // shopfront went English; the check must track whatever the config ships.
    private const PLACEHOLDER_PARTNER_PREFIX = 'Partner Name';

    /** @var list<LaunchCheck>|null */
    private ?array $memo = null;

    public function __construct(private readonly LedgerReconciliation $ledger) {}

    /**
     * @return list<LaunchCheck>
     *
     * Computed once per request. The dashboard widget asks whether to show
     * itself and then asks again for what to show, and the page asks three
     * more times from its own template — while the password check runs bcrypt
     * once per staff account, which is deliberately slow. Bound `scoped` in
     * AppServiceProvider so a queue worker does not answer next week's
     * question from a figure it read on Monday.
     */
    public function checks(): array
    {
        return $this->memo ??= [
            ...$this->automatic(),
            ...$this->attested(),
        ];
    }

    /** Drop the cache — for tests, and for anything that changes an answer. */
    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * What is still in the way, worst first.
     *
     * Failing checks before unattested ones: a check that fails is a fact
     * about the system right now, where an unattested item may only mean
     * nobody has recorded a thing that was done months ago.
     *
     * @return list<LaunchCheck>
     */
    public function outstandingChecks(): array
    {
        $failing = array_values(array_filter($this->checks(), fn (LaunchCheck $c) => ! $c->lulus));

        usort($failing, fn (LaunchCheck $a, LaunchCheck $b) => match (true) {
            $a->jenis === $b->jenis => 0,
            $a->jenis === LaunchCheckKind::Otomatis => -1,
            default => 1,
        });

        return $failing;
    }

    /** How many are still outstanding. The number the screen leads with. */
    public function outstanding(): int
    {
        return count(array_filter($this->checks(), fn (LaunchCheck $c) => ! $c->lulus));
    }

    public function isReady(): bool
    {
        return $this->outstanding() === 0;
    }

    /** @return list<LaunchCheck> */
    private function automatic(): array
    {
        return array_map(
            fn (array $one) => $this->survives($one[0], $one[1], $one[2]),
            [
                ['identitas_perusahaan', 'Identitas perusahaan', fn () => $this->companyIdentity()],
                ['mitra', 'Daftar mitra', fn () => $this->partners()],
                ['identitas_pajak', 'Identitas pajak', fn () => $this->taxIdentity()],
                ['daftar_harga', 'Daftar harga', fn () => $this->priceList()],
                ['rekening', 'Rekening perusahaan', fn () => $this->bankAccount()],
                ['sandi_staf', 'Sandi staf', fn () => $this->staffPasswords()],
                ['cadangan', 'Cadangan', fn () => $this->backups()],
                ['order_sungguhan', 'Order sungguhan', fn () => $this->realOrder()],
                ['akun_kontrol', 'Akun kontrol', fn () => $this->controlAccounts()],
            ],
        );
    }

    /**
     * Run one check, and let it fail without taking the report with it.
     *
     * The whole point of this screen is to answer "is anything in the way",
     * and it used to answer a stack trace. `staffPasswords()` reads through
     * the cache; with Redis unreachable the exception escaped, `launch:check`
     * exited 1 having printed nothing, and the readiness page 500'd — so the
     * fifteen other answers, every one of which was available, were lost to
     * the one that was not.
     *
     * A check that cannot run is reported as not passing, named, and told
     * apart from one that ran and found a problem: "could not check" and
     * "checked and it is wrong" call for different actions, and a launch
     * checklist that blurs them is worse than one that is simply slow.
     */
    private function survives(string $kunci, string $judul, callable $check): LaunchCheck
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return LaunchCheck::checked(
                kunci: $kunci,
                judul: $judul,
                keterangan: 'Pemeriksaan ini tidak bisa dijalankan, jadi statusnya belum diketahui.',
                lulus: false,
                temuan: 'Gagal diperiksa: '.$e->getMessage(),
                tindakan: 'Periksa layanan pendukung (cache/Redis, basis data), lalu muat ulang.',
            );
        }
    }

    private function companyIdentity(): LaunchCheck
    {
        $legal = config('perusahaan.legal');
        $kontak = config('perusahaan.kontak');

        $missing = [];

        foreach (['nib' => 'NIB', 'npwp' => 'NPWP'] as $key => $label) {
            if (blank($legal[$key] ?? null)) {
                $missing[] = $label;
            }
        }

        foreach (self::PLACEHOLDER_CONTACT as $key => $placeholder) {
            if (blank($kontak[$key] ?? null) || $kontak[$key] === $placeholder) {
                $missing[] = $key;
            }
        }

        return LaunchCheck::checked(
            kunci: 'identitas_perusahaan',
            judul: 'Identitas perusahaan sudah diisi',
            keterangan: 'NIB, NPWP dan kontak yang tampil di situs publik. Yang bawaan '
                .'masih contoh, dan alamat contoh di situs yang sudah terdaftar PSE '
                .'adalah masalah tersendiri.',
            lulus: $missing === [],
            temuan: $missing === [] ? null : 'Belum diisi atau masih contoh: '.implode(', ', $missing),
            tindakan: 'Isi di Pengaturan → Pengaturan perusahaan',
        );
    }

    private function partners(): LaunchCheck
    {
        /*
         * The one item on this list with a legal edge to it. Naming a company
         * as a joint-venture partner in public is a claim about a real
         * business relationship, and the shipped names are invented.
         */
        $mitra = config('perusahaan.mitra', []);

        $placeholders = array_values(array_filter(
            array_column($mitra, 'nama'),
            fn (string $nama) => str_starts_with($nama, self::PLACEHOLDER_PARTNER_PREFIX),
        ));

        return LaunchCheck::checked(
            kunci: 'mitra_bukan_contoh',
            judul: 'Mitra di situs publik bukan nama contoh',
            keterangan: 'Menyebut perusahaan sebagai mitra di halaman publik adalah klaim '
                .'tentang hubungan bisnis yang nyata. Nama bawaan semuanya karangan.',
            lulus: $placeholders === [],
            temuan: $placeholders === []
                ? null
                : count($placeholders).' mitra masih bernama contoh',
            tindakan: 'Ubah daftar mitra di Pengaturan → Pengaturan perusahaan',
        );
    }

    private function taxIdentity(): LaunchCheck
    {
        $penjual = config('pajak.penjual');

        $missing = array_keys(array_filter(
            ['npwp' => $penjual['npwp'] ?? null, 'nama' => $penjual['nama'] ?? null],
            fn ($v) => blank($v),
        ));

        return LaunchCheck::checked(
            kunci: 'identitas_pajak',
            judul: 'Identitas penjual untuk faktur pajak sudah diisi',
            keterangan: 'NPWP dan nama wajib pajak yang tercetak di setiap faktur. Ekspor '
                .'faktur pajak tidak bisa jalan tanpa keduanya.',
            lulus: $missing === [],
            temuan: $missing === [] ? null : 'Belum diisi: '.implode(', ', $missing),
            tindakan: 'Isi di Pengaturan → Pengaturan perusahaan (Identitas penjual)',
        );
    }

    private function priceList(): LaunchCheck
    {
        /*
         * A fresh install ships no prices on purpose — a seeded price is a
         * price nobody approved — which means it cannot price a single order
         * until somebody imports and publishes a real list. Worth saying here
         * rather than discovering on the first morning.
         */
        $published = PriceListVersion::query()->whereNotNull('published_at')->count();

        return LaunchCheck::checked(
            kunci: 'daftar_harga',
            judul: 'Daftar harga sudah terbit',
            keterangan: 'Sistem sengaja tidak membawa harga apa pun. Tanpa versi harga yang '
                .'sudah diterbitkan, satu order pun tidak bisa dihitung.',
            lulus: $published > 0,
            temuan: $published > 0 ? "{$published} versi sudah terbit" : 'Belum ada versi terbit',
            tindakan: 'Impor harga → tinjau selisih → terbitkan',
        );
    }

    private function bankAccount(): LaunchCheck
    {
        /*
         * There is no payment gateway: every faktur and the portal print
         * this account as the place to send money. The placeholder shipping
         * in config would send customer transfers to a number that belongs
         * to nobody — a failure that looks like success right up until the
         * first payment never arrives.
         */
        $nomor = (string) config('perusahaan.rekening.nomor');

        $placeholder = blank($nomor) || str_contains($nomor, '000-000');

        return LaunchCheck::checked(
            kunci: 'rekening',
            judul: 'Rekening perusahaan sudah diisi',
            keterangan: 'Nomor rekening ini tercetak di setiap faktur dan di portal sebagai '
                .'tujuan transfer. Placeholder berarti uang pelanggan dikirim ke nomor '
                .'yang bukan milik siapa-siapa.',
            lulus: ! $placeholder,
            temuan: $placeholder ? 'Masih placeholder: '.($nomor ?: '(kosong)') : null,
            tindakan: 'Isi di Pengaturan → Pengaturan perusahaan (Rekening)',
        );
    }

    private function staffPasswords(): LaunchCheck
    {
        /*
         * The seeder creates one account per role with the password
         * `password`, which is right for a fresh install and indefensible on a
         * live one. Checked by hashing rather than by trusting anybody to
         * remember whether they changed it.
         *
         * The verdict is cached against the stored hash itself, and that is
         * what makes the cache sound: whether `password` verifies against a
         * given bcrypt hash is a fixed fact — change the password and the
         * hash changes, so the key changes and the fact is computed once for
         * the new hash. Without this, the Owner's dashboard paid one bcrypt
         * per staff account on every load (measured: ~900ms at three
         * accounts, and it grows with headcount), for answers that had not
         * changed since the last load.
         */
        $seeded = User::query()
            ->whereIn('role', array_column(Role::cases(), 'value'))
            ->get()
            ->filter(fn (User $u) => Cache::rememberForever(
                'sandi-bawaan:'.md5((string) $u->password),
                fn () => Hash::check('password', (string) $u->password),
            ));

        return LaunchCheck::checked(
            kunci: 'sandi_staf',
            judul: 'Sandi staf sudah diganti dari bawaan',
            keterangan: 'Seeder membuat satu akun per peran dengan sandi `password`. '
                .'Termasuk akun Pemilik, yang bisa melihat seluruh buku besar.',
            lulus: $seeded->isEmpty(),
            temuan: $seeded->isEmpty()
                ? null
                : $seeded->count().' akun masih memakai sandi bawaan: '
                    .$seeded->pluck('email')->implode(', '),
            tindakan: 'Ganti lewat halaman profil masing-masing',
        );
    }

    private function backups(): LaunchCheck
    {
        /*
         * Off-box is the part that matters. A backup on the machine it
         * protects is a copy, not a backup — it dies with the disk it is
         * meant to survive.
         *
         * This proves a backup was *written*. Whether a restore has ever been
         * practised is a separate item, and it cannot be checked from here.
         */
        $offsite = BackupRun::query()
            // `verified`, not merely finished: the artefact was read back after
            // writing, so this proves a file exists that can at least be
            // opened. Whether it restores is the attested item below.
            ->verified()
            ->where('offsite', true)
            ->latest('finished_at')
            ->first();

        return LaunchCheck::checked(
            kunci: 'cadangan',
            judul: 'Cadangan pernah terverifikasi dan tersimpan di luar server',
            keterangan: 'Cadangan yang tersimpan di server yang dilindunginya bukan '
                .'cadangan — ia ikut mati bersama disk yang seharusnya ia selamatkan.',
            lulus: $offsite !== null,
            temuan: $offsite === null
                ? 'Belum ada cadangan sukses yang tersimpan di luar server'
                : 'Terakhir '.$offsite->finished_at?->format('d/m/Y H:i'),
            tindakan: 'php artisan backup:run',
        );
    }

    private function realOrder(): LaunchCheck
    {
        /*
         * Build order phase 1: staff run the real business through the admin
         * panel before any buyer has a login. One order that went all the way
         * to completed is the cheapest possible proof that the chain works on
         * the real server — pricing, credit, stock, invoice, payment,
         * shipment, ledger.
         */
        $completed = Order::query()->where('status', OrderStatus::Completed)->count();

        return LaunchCheck::checked(
            kunci: 'order_nyata',
            judul: 'Satu order nyata sudah selesai dari ujung ke ujung',
            keterangan: 'Harga, kredit, stok, faktur, pembayaran, pengiriman dan jurnal — '
                .'satu order yang sampai selesai membuktikan semuanya sekaligus.',
            lulus: $completed > 0,
            temuan: $completed > 0 ? "{$completed} order selesai" : 'Belum ada order yang selesai',
            tindakan: 'Buat order di panel admin dan jalankan sampai selesai',
        );
    }

    private function controlAccounts(): LaunchCheck
    {
        /*
         * Not on the DEPLOY.md list, and it belongs here more than most of
         * what is. Every other item asks whether a setting was filled in; this
         * asks whether the books this system has been keeping actually add up.
         * Going live with a control account already adrift means never knowing
         * whether the drift came from before or after.
         */
        $drifted = array_values(array_filter(
            $this->ledger->checks(),
            fn ($check) => ! $check->agrees(),
        ));

        return LaunchCheck::checked(
            kunci: 'akun_kontrol',
            judul: 'Semua akun kontrol cocok dengan buku pembantunya',
            keterangan: 'Kalau sudah melenceng sebelum go-live, tidak akan pernah jelas '
                .'apakah selisihnya datang dari sebelum atau sesudah.',
            lulus: $drifted === [],
            temuan: $drifted === []
                ? null
                : 'Tidak cocok: '.implode(', ', array_map(fn ($c) => $c->nama, $drifted)),
            tindakan: 'Buku besar → Neraca saldo',
        );
    }

    /**
     * The items nothing in here can see.
     *
     * @return list<LaunchCheck>
     */
    private function attested(): array
    {
        $signed = LaunchAttestation::query()->with('attestedBy')->get()->keyBy('kunci');

        $items = [
            [
                'pse',
                'Terdaftar PSE Lingkup Privat',
                'Wajib sebelum sistem dipakai pengguna, lewat OSS → PB-UMKU. Gratis, tapi '
                .'paling lama prosesnya — ini yang paling awal harus dimulai.',
            ],
            [
                'kbli',
                'KBLI di NIB sudah dicek',
                'Kegiatan usaha yang terdaftar harus mencakup apa yang benar-benar dijual '
                .'di sini. Langkah nol di urutan pembangunan, dan tidak bisa dilihat dari sini.',
            ],
            [
                'legal_ditinjau',
                'Kebijakan privasi dan syarat penjualan sudah dibaca pengacara',
                'Keduanya disusun mengikuti UU PDP 27/2022 dan praktik B2B biasa. Disusun, '
                .'bukan ditinjau.',
            ],
            [
                'nilai_komersial',
                'Denda keterlambatan dan batas klaim sudah diputuskan',
                'Keduanya punya nilai bawaan di config/legal.php yang belum disetujui siapa '
                .'pun. Tarif denda yang tidak pernah ditagih membuat seluruh dokumen '
                .'terlihat basa-basi.',
            ],
            [
                'format_faktur',
                'Format ekspor faktur pajak sudah dipastikan ke akuntan',
                'CSV e-Faktur atau XML Coretax. Hanya bentuk berkasnya yang belum pasti, '
                .'tapi itu tetap belum pasti.',
            ],
            [
                'restore_dilatih',
                'Pemulihan cadangan sudah pernah dilatih',
                'Cadangan yang belum pernah dipulihkan adalah dugaan, bukan cadangan. '
                .'Sistem hanya bisa melihat bahwa berkasnya ditulis.',
            ],
        ];

        return array_map(function (array $item) use ($signed) {
            [$kunci, $judul, $keterangan] = $item;
            $attestation = $signed->get($kunci);

            return LaunchCheck::attested(
                kunci: $kunci,
                judul: $judul,
                keterangan: $keterangan,
                sudah: $attestation !== null,
                temuan: $attestation === null ? null : sprintf(
                    'Dinyatakan %s oleh %s%s',
                    $attestation->attested_at?->format('d/m/Y'),
                    $attestation->attestedBy?->name ?? '—',
                    blank($attestation->catatan) ? '' : ' · '.$attestation->catatan,
                ),
            );
        }, $items);
    }
}
