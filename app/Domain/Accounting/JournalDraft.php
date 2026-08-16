<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Supplier;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An entry being assembled, before anything is written.
 *
 * The point of a draft is that a posting rule reads as the accountant would
 * say it — debit this, credit that — and that the balance check happens once,
 * in one place, on something that is not yet in the database. By the time
 * Ledger touches a row, the entry is already known to be sound.
 *
 * Amounts are always positive. Which side a figure lands on is the posting
 * rule's decision and has to be written down as one, because "a negative
 * debit" is the shape of a bug that reconciles.
 */
final class JournalDraft
{
    /** @var list<array{kode: string, debit: int, kredit: int, memo: ?string, company_id: ?int, supplier_id: ?int}> */
    private array $lines = [];

    private function __construct(
        public readonly string $jenis,
        public readonly string $keterangan,
        public readonly Carbon $tanggal,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
    ) {}

    /**
     * An entry caused by a document.
     *
     * `$jenis` says which of that document's postings this is — an order posts
     * revenue when it is invoiced and cost when it is shipped, and those are
     * two entries about one row.
     */
    public static function for(
        Model $source,
        string $jenis,
        string $keterangan,
        ?DateTimeInterface $tanggal = null,
    ): self {
        return new self(
            jenis: $jenis,
            keterangan: $keterangan,
            tanggal: $tanggal ? Carbon::parse($tanggal) : Carbon::now(),
            sourceType: $source::class,
            sourceId: (string) $source->getKey(),
        );
    }

    /**
     * An entry somebody wrote by hand: an opening balance, an accrual, a
     * correction an accountant decided on. Nothing behind it but their word,
     * which is why Ledger::postManual() insists on knowing whose word.
     */
    public static function manual(string $keterangan, ?DateTimeInterface $tanggal = null): self
    {
        return new self(
            jenis: JournalEntry::JENIS_MANUAL,
            keterangan: $keterangan,
            tanggal: $tanggal ? Carbon::parse($tanggal) : Carbon::now(),
            sourceType: null,
            sourceId: null,
        );
    }

    public function debit(
        string $kode,
        int $amountRupiah,
        ?string $memo = null,
        ?Company $company = null,
        ?Supplier $supplier = null,
    ): self {
        return $this->add($kode, $amountRupiah, 0, $memo, $company, $supplier);
    }

    public function kredit(
        string $kode,
        int $amountRupiah,
        ?string $memo = null,
        ?Company $company = null,
        ?Supplier $supplier = null,
    ): self {
        return $this->add($kode, 0, $amountRupiah, $memo, $company, $supplier);
    }

    /**
     * A figure whose side depends on its sign — a variance, essentially.
     *
     * A supplier can bill more than the goods were received at or less, and
     * the rule that posts it should not have to fork. Positive debits,
     * negative credits, and the caller states which way round that is by
     * choosing this method rather than by passing a minus sign to debit().
     */
    public function debitSigned(
        string $kode,
        int $amountRupiah,
        ?string $memo = null,
        ?Company $company = null,
        ?Supplier $supplier = null,
    ): self {
        return $amountRupiah >= 0
            ? $this->debit($kode, $amountRupiah, $memo, $company, $supplier)
            : $this->kredit($kode, -$amountRupiah, $memo, $company, $supplier);
    }

    /** The mirror of debitSigned(): positive credits, negative debits. */
    public function kreditSigned(
        string $kode,
        int $amountRupiah,
        ?string $memo = null,
        ?Company $company = null,
        ?Supplier $supplier = null,
    ): self {
        return $amountRupiah >= 0
            ? $this->kredit($kode, $amountRupiah, $memo, $company, $supplier)
            : $this->debit($kode, -$amountRupiah, $memo, $company, $supplier);
    }

    /** @return list<array{kode: string, debit: int, kredit: int, memo: ?string, company_id: ?int, supplier_id: ?int}> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): int
    {
        return array_sum(array_column($this->lines, 'debit'));
    }

    public function totalKredit(): int
    {
        return array_sum(array_column($this->lines, 'kredit'));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalKredit();
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Every account code the draft touches, for a single lookup at post time. */
    public function accountCodes(): array
    {
        return array_values(array_unique(array_column($this->lines, 'kode')));
    }

    private function add(
        string $kode,
        int $debit,
        int $kredit,
        ?string $memo,
        ?Company $company,
        ?Supplier $supplier,
    ): self {
        if ($debit < 0 || $kredit < 0) {
            throw new LogicException(
                "A journal line cannot be negative ({$kode}). Put the amount on the other side instead."
            );
        }

        /*
         * Zero lines are dropped rather than refused. PPN is genuinely nil on
         * some documents, and a rule that has to branch around that is a rule
         * with an untested branch in it.
         */
        if ($debit === 0 && $kredit === 0) {
            return $this;
        }

        $this->lines[] = [
            'kode' => $kode,
            'debit' => $debit,
            'kredit' => $kredit,
            'memo' => $memo,
            'company_id' => $company?->id,
            'supplier_id' => $supplier?->id,
        ];

        return $this;
    }
}
