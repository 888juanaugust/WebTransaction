<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Posting\PostingBuilder;
use App\Models\GeneralLedger\Posting;
use App\Models\Settlement\PaymentAllocation;
use Illuminate\Database\Eloquent\Relations\Relation;

/** Writes the allocations a payment's posting declared and refreshes the settled documents. Registered on the PostingService. */
final class AllocationLedger
{
    public function __construct(private readonly SettlementService $settlement, private readonly ApprovalEngine $approvals) {}

    public function write(Posting $posting, PostingBuilder $builder): void
    {
        $touched = [];
        foreach ($builder->allocations() as $i => $a) {
            // A document still waiting for approval, or rejected, cannot be settled.
            $class = Relation::getMorphedModel((string) $a['receivable_type']) ?? throw new \RuntimeException(__('Only a document can be settled.'));
            if (($settled = $class::query()->find($a['receivable_id'])) !== null) {
                $this->approvals->assertApproved($settled, __('is not approved; it cannot be settled yet.'));
            }
            PaymentAllocation::query()->create([
                'posting_id' => $posting->id,
                'sort' => $i,
                'payment_type' => $posting->document_type,
                'payment_id' => $posting->document_id,
                'receivable_type' => $a['receivable_type'],
                'receivable_id' => $a['receivable_id'],
                'amount' => (int) $a['amount'],
                'discount' => (int) ($a['discount'] ?? 0),
                'discount_account_id' => $a['discount_account_id'] ?? null,
                'fc_amount' => $a['fc_amount'] ?? null,
                'fc_discount' => $a['fc_discount'] ?? null,
                'fx_difference' => (int) ($a['fx_difference'] ?? 0),
                'trans_date' => $posting->trans_date->toDateString(),
            ]);
            $touched["{$a['receivable_type']}:{$a['receivable_id']}"] = [$a['receivable_type'], (int) $a['receivable_id']];
        }
        $this->refresh($touched);
    }

    public function unwrite(Posting $posting): void
    {
        $touched = [];
        foreach (PaymentAllocation::query()->where('posting_id', $posting->id)->get(['receivable_type', 'receivable_id']) as $a) {
            $touched["{$a->receivable_type}:{$a->receivable_id}"] = [$a->receivable_type, (int) $a->receivable_id];
        }
        $this->refresh($touched);
    }

    private function refresh(array $touched): void
    {
        foreach ($touched as [$type, $id]) {
            $class = Relation::getMorphedModel((string) $type);
            $doc = $class !== null ? $class::query()->find($id) : null;
            if ($doc !== null) {
                $this->settlement->refresh($doc);
            }
        }
    }
}
