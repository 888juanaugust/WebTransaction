<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use DomainException;

/**
 * Debits did not equal credits, so nothing was written.
 *
 * A distinct exception type because this is the one failure a posting rule can
 * cause that means the rule itself is wrong, not that the data was. The
 * message carries both totals and the difference, because "unbalanced" on its
 * own tells whoever reads the log nothing they can act on — the size of the
 * gap is usually the whole diagnosis, and a gap equal to a line's PPN or to
 * one rupiah of rounding names its own cause.
 */
class UnbalancedJournalException extends DomainException
{
    public function __construct(public readonly JournalDraft $draft)
    {
        $debit = $draft->totalDebit();
        $kredit = $draft->totalKredit();

        parent::__construct(sprintf(
            'Jurnal tidak seimbang (%s): debit %s, kredit %s, selisih %s.',
            $draft->keterangan,
            number_format($debit, 0, ',', '.'),
            number_format($kredit, 0, ',', '.'),
            number_format($debit - $kredit, 0, ',', '.'),
        ));
    }

    public function difference(): int
    {
        return $this->draft->totalDebit() - $this->draft->totalKredit();
    }
}
