<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

/**
 * Where a part comes from, commercially: imported by us, imported on
 * consignment through somebody else, or bought locally.
 *
 * Not the same axis as `kategori` (what the part *is* — hydraulic, bearing)
 * or `merk` (whose name is on the box). A brand can be sourced two ways in the
 * same month, and the owner wanted the three streams told apart because they
 * are priced, paid for and reported on differently: an imported line carries
 * freight and duty and a supplier abroad; titip impor carries somebody else's
 * capital; lokal carries neither. The sales report groups on it, and the
 * import-purchasing commission is paid on the first of the three alone.
 *
 * Nullable on the product, deliberately. The existing catalogue predates the
 * distinction and nobody has said which stream each of a thousand SKUs came
 * through; writing `lokal` on all of them would be a claim, not a default.
 * An ungrouped SKU reports as "Belum digolongkan" until somebody says.
 */
enum Golongan: string
{
    case Impor = 'impor';
    case TitipImpor = 'titip_impor';
    case Lokal = 'lokal';

    public function label(): string
    {
        return match ($this) {
            self::Impor => 'Impor',
            self::TitipImpor => 'Titip impor',
            self::Lokal => 'Lokal',
        };
    }

    /** What an unassigned SKU is called wherever the three are listed. */
    public const BELUM = 'Belum digolongkan';

    /**
     * Read a cell the way people type it: case, spacing and the underscore
     * all forgiven, so "TITIP IMPOR", "titip_impor" and "Titip Impor" are one
     * value. Null for blank; null for unknown too — the caller decides which
     * of those it is looking at by checking the raw text.
     */
    public static function dariTeks(string $raw): ?self
    {
        $key = strtolower(preg_replace('/[\s_]+/', '_', trim($raw)) ?? '');

        return self::tryFrom($key);
    }

    /** @return array<string, string> value => label, for a select */
    public static function pilihan(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
