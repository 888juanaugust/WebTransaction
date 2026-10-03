<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ACCURATE's Preferensi: switches and numbers that change how the system
 * behaves, set by the business on a screen rather than in `.env`.
 *
 * Two kinds, both stored in `pengaturan` under a `pref.` prefix so they can
 * never collide with the company's own values:
 *
 * - **Switches** (`Fitur`): '1' on, '0' off, nothing stored = the default,
 *   which for every switch is on — today's behaviour.
 * - **Numbers**, read through `angka()`: what the business stored, else the
 *   config key named beside it (and so `.env`), which stays the default.
 *
 * Not laid over config at boot the way PengaturanPerusahaan's values are: a
 * queue worker boots once and runs for days, so a boot-time overlay would
 * keep the nightly debt sweep on last week's numbers until the next restart.
 * Read through this class, a change is seen by the next request and the next
 * job alike — it is scoped (AppServiceProvider), resolved once per request
 * or job, because a page asks `Fitur::X->aktif()` many times and each ask
 * must not be a cache round trip.
 */
final class Preferensi
{
    public const PREFIX = 'pref.';

    /**
     * Numbers: kunci → the config path that holds its default, its bounds,
     * and the switch it belongs to (shown beside it on the screen).
     *
     * @var array<string, array{config: string, label: string, min: int, max: int, fitur: Fitur}>
     */
    public const ANGKA = [
        'piutang_hari_peringatan' => [
            'config' => 'penjualan.debt_notice_days',
            'label' => 'Umur peringatan (hari sejak tanggal faktur)',
            'min' => 1,
            'max' => 3650,
            'fitur' => Fitur::PeringatanPiutang,
        ],
        'piutang_hari_beku' => [
            'config' => 'penjualan.debt_freeze_days',
            'label' => 'Umur beku (hari sejak tanggal faktur)',
            'min' => 1,
            'max' => 3650,
            'fitur' => Fitur::BekuKredit,
        ],
    ];

    /** @var array<string, string|null>|null */
    private ?array $tersimpan = null;

    public function __construct(private readonly PengaturanStore $store) {}

    public function aktif(Fitur $fitur): bool
    {
        // A switch whose code has not landed answers "on" whatever is stored:
        // nothing reads it yet, and "off" would be a promise nothing keeps.
        if (! $fitur->diterapkan()) {
            return true;
        }

        return ($this->tersimpan()[self::PREFIX.$fitur->value] ?? null) !== '0';
    }

    /** The number in force: what the business stored, else its default. */
    public function angka(string $kunci): int
    {
        return $this->angkaTersimpan($kunci) ?? $this->bawaan($kunci);
    }

    /** What the business stored for this number, or null while it follows its default. */
    public function angkaTersimpan(string $kunci): ?int
    {
        $nilai = $this->tersimpan()[self::PREFIX.$kunci] ?? null;

        return $nilai !== null && ctype_digit($nilai) ? (int) $nilai : null;
    }

    /** The default a number returns to when the business clears it: config, so `.env`. */
    public function bawaan(string $kunci): int
    {
        $definisi = self::ANGKA[$kunci] ?? throw new InvalidArgumentException("Preferensi tidak dikenal: {$kunci}");

        return (int) config($definisi['config']);
    }

    /**
     * Save from the Preferensi screen. Every change is audited with old and
     * new: turning off a credit freeze is exactly the kind of change somebody
     * will later ask "who did that, and when".
     *
     * @param  array<string, bool>  $fitur  Fitur value => on/off
     * @param  array<string, int|string|null>  $angka  ANGKA kunci => number (null = back to default)
     */
    public function simpan(array $fitur, array $angka, User $actor): void
    {
        $nilai = [];

        foreach ($fitur as $kunci => $on) {
            $f = Fitur::tryFrom($kunci) ?? throw new InvalidArgumentException("Saklar tidak dikenal: {$kunci}");

            if (! $f->diterapkan()) {
                throw new InvalidArgumentException("Saklar {$kunci} belum berlaku (fase {$f->berlakuMulaiFase()}).");
            }

            // On and never stored is already the default: writing '1' would
            // only put "changed" rows in the audit log that nobody changed.
            if ($on && ($this->tersimpan()[self::PREFIX.$kunci] ?? null) === null) {
                continue;
            }

            $nilai[self::PREFIX.$kunci] = $on ? '1' : '0';
        }

        // The number each preference will have once this saves.
        $akan = [];

        foreach (array_keys(self::ANGKA) as $kunci) {
            $akan[$kunci] = $this->angka($kunci);
        }

        foreach ($angka as $kunci => $isi) {
            $definisi = self::ANGKA[$kunci] ?? throw new InvalidArgumentException("Preferensi tidak dikenal: {$kunci}");

            if ($isi === null || $isi === '') {
                $nilai[self::PREFIX.$kunci] = null;
                $akan[$kunci] = $this->bawaan($kunci);

                continue;
            }

            $n = filter_var($isi, FILTER_VALIDATE_INT);

            if ($n === false || $n < $definisi['min'] || $n > $definisi['max']) {
                throw ValidationException::withMessages([
                    "angka.{$kunci}" => "{$definisi['label']}: isi bilangan bulat {$definisi['min']}–{$definisi['max']}.",
                ]);
            }

            $nilai[self::PREFIX.$kunci] = (string) $n;
            $akan[$kunci] = $n;
        }

        // The reminder has to come before the freeze, or the customer is
        // frozen without ever having been told.
        $peringatan = $akan['piutang_hari_peringatan'];
        $beku = $akan['piutang_hari_beku'];

        if ($peringatan >= $beku) {
            throw ValidationException::withMessages([
                'angka.piutang_hari_beku' => "Umur beku ({$beku} hari) harus lebih panjang dari umur peringatan ({$peringatan} hari).",
            ]);
        }

        $this->store->tulis(
            action: 'preferensi_diubah',
            nilai: $nilai,
            actor: $actor,
        );

        $this->lupakan();
    }

    /** Drop the per-request copy, so the next ask reads what is stored now. */
    public function lupakan(): void
    {
        $this->tersimpan = null;
    }

    /** @return array<string, string|null> */
    private function tersimpan(): array
    {
        return $this->tersimpan ??= ($this->store->semua() ?? []);
    }
}
