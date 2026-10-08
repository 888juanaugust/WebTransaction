<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Domain\Currency\ForeignAmount;
use App\Domain\Posting\Exceptions\UnbalancedPostingException;

/**
 * Collects what a document posts. Journal lines now; stock movements and
 * payment allocations are added by the modules that need them, so one
 * builder carries every effect of one document.
 */
final class PostingBuilder
{
    /** @var list<array{account_id: int, debit: int, credit: int, memo: ?string, branch_id: ?int, department_id: ?int, project_id: ?int}> */
    private array $journal = [];

    /** @var list<array<string, mixed>> */
    private array $stock = [];

    /** @var list<array<string, mixed>> */
    private array $allocations = [];

    /** @param  Tags|null  $defaultTags  the document's department and project, for lines that name none */
    public function __construct(private readonly ?int $defaultBranchId = null, private readonly ?Tags $defaultTags = null) {}

    public function debit(int $accountId, int $amount, ?string $memo = null, ?int $branchId = null, ?Tags $tags = null, ?ForeignAmount $foreign = null): self
    {
        return $this->line($accountId, $amount, 0, $memo, $branchId, $tags, $foreign);
    }

    public function credit(int $accountId, int $amount, ?string $memo = null, ?int $branchId = null, ?Tags $tags = null, ?ForeignAmount $foreign = null): self
    {
        return $this->line($accountId, 0, $amount, $memo, $branchId, $tags, $foreign);
    }

    /** A signed amount: positive debits, negative credits; zero is dropped. */
    public function signed(int $accountId, int $amount, ?string $memo = null, ?int $branchId = null, ?Tags $tags = null, ?ForeignAmount $foreign = null): self
    {
        return $amount >= 0 ? $this->debit($accountId, $amount, $memo, $branchId, $tags, $foreign) : $this->credit($accountId, -$amount, $memo, $branchId, $tags, $foreign);
    }

    /** @param  ForeignAmount|null  $foreign  what the line moves in a foreign currency (debit positive); only for foreign-currency bank accounts */
    private function line(int $accountId, int $debit, int $credit, ?string $memo, ?int $branchId, ?Tags $tags, ?ForeignAmount $foreign = null): self
    {
        if ($debit === 0 && $credit === 0 && ($foreign === null || $foreign->amount === 0)) {
            return $this;
        }
        if ($debit < 0 || $credit < 0) {
            return $this->line($accountId, max(0, -$credit), max(0, -$debit), $memo, $branchId, $tags, $foreign);
        }
        $this->journal[] = [
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => $memo,
            'branch_id' => $branchId ?? $this->defaultBranchId,
        ] + ($tags ?? Tags::none())->orElse($this->defaultTags)->toArray()
            + ($foreign !== null ? ['currency_id' => $foreign->currencyId, 'fc_amount' => $foreign->amount] : []);

        return $this;
    }

    /** @param  array<string, mixed>  $movement  item_id, warehouse_id, direction, base_quantity, unit_cost, source_line_type, source_line_id */
    public function stock(array $movement): self
    {
        $this->stock[] = $movement;

        return $this;
    }

    /** @param  array<string, mixed>  $allocation  payment / receivable pair and the amount applied */
    public function allocate(array $allocation): self
    {
        $this->allocations[] = $allocation;

        return $this;
    }

    /** @return list<array{account_id: int, debit: int, credit: int, memo: ?string, branch_id: ?int, department_id: ?int, project_id: ?int}> */
    public function journalLines(): array
    {
        return $this->journal;
    }

    /** @return list<array<string, mixed>> */
    public function stockMovements(): array
    {
        return $this->stock;
    }

    /** @return list<array<string, mixed>> */
    public function allocations(): array
    {
        return $this->allocations;
    }

    public function totalDebit(): int
    {
        return array_sum(array_column($this->journal, 'debit'));
    }

    public function totalCredit(): int
    {
        return array_sum(array_column($this->journal, 'credit'));
    }

    public function isEmpty(): bool
    {
        return $this->journal === [] && $this->stock === [] && $this->allocations === [];
    }

    public function assertBalanced(): void
    {
        if ($this->totalDebit() !== $this->totalCredit()) {
            throw new UnbalancedPostingException(sprintf(
                'Journal does not balance: debit %d, credit %d.',
                $this->totalDebit(),
                $this->totalCredit(),
            ));
        }
    }
}
