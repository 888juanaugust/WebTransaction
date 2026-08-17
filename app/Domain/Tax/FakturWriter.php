<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * Turning fakturs into the file the tax office wants.
 *
 * # Read this before the first real filing
 *
 * **Which file the tax office wants is not settled, and nothing in this
 * repository can settle it.** `CLAUDE.md` specifies a CSV in the e-Faktur
 * import layout, which is what the desktop application accepted for years and
 * what the accountant would have handed over before 2025. Coretax, live since
 * January 2025, is widely reported to take XML instead. Both cannot be right,
 * and getting it wrong is not a bug that shows up in testing — it is a
 * rejected upload a week before the filing deadline, or worse, an accepted
 * upload of the wrong figures.
 *
 * So this is an interface with one implementation rather than a function.
 * The mapping — which invoice, whose NPWP, what the DPP is per line — is
 * settled and lives in FakturRecord. Only the serialisation is in doubt, and
 * it is the smaller half. When the accountant produces a real template:
 *
 *  - if it is the CSV layout, diff their template's header against the columns
 *    declared in EFakturCsvWriter. They are written out as data, one per line,
 *    for exactly that comparison.
 *  - if it is XML, write a second class against this interface. Nothing above
 *    it needs to change, and `faktur_exports.format` already records which
 *    layout each past filing used.
 *
 * What has deliberately **not** been done is guess at an XML schema. A
 * plausible-looking tax file that is subtly wrong is worse than no file: the
 * CSV below is at least a format somebody can recognise and check against
 * their own template, whereas an invented schema would look authoritative and
 * be unverifiable.
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
