<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * How long an asset is written off over, per UU PPh Pasal 11.
 *
 * Indonesian tax law does not leave useful life to judgement: tangible assets
 * fall into four groups with fixed lives, and buildings have their own two.
 * Picking a life because it feels right is how a depreciation charge gets
 * disallowed years later, so the choice here is which **group** an asset is in,
 * and the months follow.
 *
 *   Kelompok 1   4 years    computers, office equipment, hand tools
 *   Kelompok 2   8 years    vehicles, furniture, heavier equipment
 *   Kelompok 3  16 years    heavy machinery
 *   Kelompok 4  20 years    the heaviest plant — rare in a parts warehouse
 *   Bangunan permanen      20 years
 *   Bangunan non-permanen  10 years
 *
 * **Book life is set equal to tax life here, deliberately.** They are allowed
 * to differ, and in a large company they do — which produces deferred tax, an
 * account and a set of workings this business has no use for. Aligning them
 * means the depreciation on the laba rugi is the figure that goes on the SPT.
 *
 * Only **garis lurus** (straight line) is implemented. Pasal 11 also permits
 * saldo menurun (declining balance) for non-building assets, as an election
 * made per asset and kept for its whole life. Adding it means a second formula
 * and a column recording the election; it is not a change to anything else
 * here. Confirm with the accountant before assuming which the business uses.
 */
enum DepreciationGroup: string
{
    case Kelompok1 = 'kelompok_1';
    case Kelompok2 = 'kelompok_2';
    case Kelompok3 = 'kelompok_3';
    case Kelompok4 = 'kelompok_4';
    case BangunanPermanen = 'bangunan_permanen';
    case BangunanNonPermanen = 'bangunan_non_permanen';

    public function months(): int
    {
        return match ($this) {
            self::Kelompok1 => 4 * 12,
            self::Kelompok2 => 8 * 12,
            self::Kelompok3 => 16 * 12,
            self::Kelompok4 => 20 * 12,
            self::BangunanPermanen => 20 * 12,
            self::BangunanNonPermanen => 10 * 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Kelompok1 => 'Kelompok 1 — 4 tahun',
            self::Kelompok2 => 'Kelompok 2 — 8 tahun',
            self::Kelompok3 => 'Kelompok 3 — 16 tahun',
            self::Kelompok4 => 'Kelompok 4 — 20 tahun',
            self::BangunanPermanen => 'Bangunan permanen — 20 tahun',
            self::BangunanNonPermanen => 'Bangunan non-permanen — 10 tahun',
        };
    }

    /** What typically belongs here, to make the picker answerable. */
    public function contoh(): string
    {
        return match ($this) {
            self::Kelompok1 => 'Komputer, printer, perlengkapan kantor, perkakas',
            self::Kelompok2 => 'Kendaraan operasional, rak gudang, mebel, forklift',
            self::Kelompok3 => 'Mesin berat',
            self::Kelompok4 => 'Bangunan bukan gedung, instalasi berat',
            self::BangunanPermanen => 'Gudang atau ruko permanen',
            self::BangunanNonPermanen => 'Bangunan semi permanen',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $g) => [$g->value => $g->label()])
            ->all();
    }
}
