<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Turning fakturs into the file the tax office wants.
 *
 * Two layouts exist behind this interface, and `config('pajak.format_ekspor')`
 * picks one:
 *
 *  - `coretax_xml` — the Coretax bulk-import XML, written to the template the
 *    accountant handed over in 2026-09. This is the layout in use.
 *  - `efaktur_csv` — the older desktop e-Faktur import CSV. Kept because
 *    `faktur_exports.format` records which layout each past filing used, and
 *    a filing must stay reproducible in the layout it was made in.
 *
 * The mapping — which invoice, whose NPWP, what the DPP is per line — is
 * settled and lives in FakturRecord; a writer only serialises it. That split
 * is what let the XML arrive as one new class and one config value, with
 * nothing above it moving, and it is what a third layout would take too.
 *
 * What a writer must never do is compute a tax figure. Every number comes
 * from the invoice and its line snapshots, so a filed month prints the same
 * forever whatever the rate does later.
 */
interface FakturWriter
{
    /**
     * A short stable name for this layout, stored on the export record so an
     * old filing can be read back knowing how it was written.
     */
    public function format(): string;

    /** Filename extension, without the dot. */
    public function extension(): string;

    /**
     * @param  list<FakturRecord>  $fakturs
     * @return string the complete file contents
     */
    public function write(array $fakturs): string;
}
