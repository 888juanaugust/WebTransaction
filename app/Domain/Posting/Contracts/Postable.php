<?php

declare(strict_types=1);

namespace App\Domain\Posting\Contracts;

use App\Domain\Posting\PostingBuilder;
use Carbon\CarbonInterface;

/**
 * A document the ledger knows: it says what it posts, and the posting layer
 * turns that into journal lines (and, in later modules, stock movements and
 * payment allocations). Models implement it with the PostsToLedger trait.
 */
interface Postable
{
    /** Stable identity of the document across its revisions: "sales_invoice:42". */
    public function postingKey(): string;

    public function postingDate(): CarbonInterface;

    public function postingBranchId(): ?int;

    public function postingNumber(): string;

    public function postingDescription(): ?string;

    /** Declares the effects of this document; the builder validates them. */
    public function buildPostings(PostingBuilder $builder): void;

    /** Header and lines, for document revisions. */
    public function snapshot(): array;
}
