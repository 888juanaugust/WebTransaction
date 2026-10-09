<?php

declare(strict_types=1);

namespace App\Client\Domain\Claims;

use App\Client\Models\ReturnClaim;
use App\Client\Models\ReturnClaimLine;
use App\Client\Screens\CentralScreen;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesReturn;
use App\Models\Sales\SalesReturnLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Returns on two keys: the customer's sales seat (or the Owner) files which
 * goods of an invoice come back, to which warehouse and why; Inventory
 * verifies it into a sales return made in Inventory's name — only then does
 * stock move and the customer get the credit.
 */
final class ReturnClaims
{
    public function __construct(
        private readonly TwoKeys $twoKeys,
        private readonly ApprovalEngine $approvals,
        private readonly NumberGenerator $numbers,
        private readonly DocumentRepository $documents,
    ) {}

    /** @param  list<array{sales_invoice_line_id: int, quantity: string|int|float}>  $lines */
    public function file(SalesInvoice $invoice, Warehouse $warehouse, array $lines, string $reason, User $filer): ReturnClaim
    {
        $customer = $invoice->customer;
        if (! $filer->isAdministrator() && (int) $customer->sales_user_id !== (int) $filer->id) {
            throw new RuntimeException(__('Only :name\'s sales seat or an administrator files a return for this customer.', ['name' => $customer->name]));
        }
        if (! $this->approvals->isApproved($invoice)) {
            throw new RuntimeException(__(':number is not approved; nothing can be made from it yet.', ['number' => $invoice->number]));
        }
        if (trim($reason) === '') {
            throw new RuntimeException(__('Say why the goods come back.'));
        }

        $rows = [];
        foreach ($lines as $sort => $given) {
            $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->find((int) ($given['sales_invoice_line_id'] ?? 0));
            if ($line === null) {
                throw new RuntimeException(__('A line points at a document line that does not exist.'));
            }
            $quantity = BigDecimal::of((string) ($given['quantity'] ?? 0));
            if ($quantity->isLessThanOrEqualTo(0)) {
                continue;
            }
            $ratio = BigDecimal::of((string) $line->quantity)->isZero() ? BigDecimal::one() : BigDecimal::of((string) $line->base_quantity)->dividedBy((string) $line->quantity, 8, RoundingMode::HalfUp);
            $base = $quantity->multipliedBy($ratio)->toScale(4, RoundingMode::HalfUp);
            $room = $this->returnable($line);
            if ($base->isGreaterThan($room)) {
                throw new RuntimeException(__(':item: only :room more can come back from :number.', ['item' => $line->item?->name, 'room' => $room->toScale(4, RoundingMode::HalfUp)->__toString(), 'number' => $invoice->number]));
            }
            $rows[] = ['sort' => $sort, 'sales_invoice_line_id' => $line->id, 'item_id' => $line->item_id, 'unit_id' => $line->unit_id, 'quantity' => $quantity->toScale(4, RoundingMode::HalfUp)->__toString(), 'base_quantity' => $base->__toString()];
        }
        if ($rows === []) {
            throw new RuntimeException(__('Name at least one line that comes back.'));
        }

        return DB::transaction(function () use ($invoice, $customer, $warehouse, $rows, $reason, $filer): ReturnClaim {
            $claim = ReturnClaim::query()->create([
                'customer_id' => $customer->id, 'sales_invoice_id' => $invoice->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $invoice->branch_id,
                'reason' => trim($reason), 'status' => ClaimStatus::FILED, 'filed_by' => $filer->id,
            ]);
            foreach ($rows as $row) {
                $claim->lines()->create($row);
            }
            Auditor::log('claim_filed', $claim, null, ['invoice' => $invoice->number, 'lines' => count($rows)]);

            return $claim;
        });
    }

    /** Inventory's key: the sales return is made in the verifier's name; stock comes in and the customer gets the credit. */
    public function verify(ReturnClaim $claim, User $actor, string|CarbonImmutable $date, ?string $note = null): SalesReturn
    {
        $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::ReturnClaims);

        return DB::transaction(function () use ($claim, $actor, $date, $note): SalesReturn {
            $claim = ReturnClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->twoKeys->assertMayDecide($claim, $actor, CentralScreen::ReturnClaims);
            $invoice = $claim->invoice;
            $date = CarbonImmutable::parse((string) ($date instanceof CarbonImmutable ? $date->toDateString() : $date));
            $series = $this->numbers->defaultSeries(TransactionType::SalesReturn, $actor)
                ?? throw new RuntimeException(__('No numbering series for sales returns.'));

            $return = SalesReturn::query()->create([
                'number' => $this->numbers->next($series, $date, $invoice->branch?->code),
                'series_id' => $series->id,
                'trans_date' => $date->toDateString(),
                'customer_id' => $invoice->customer_id,
                'branch_id' => $invoice->branch_id,
                'return_type' => 'invoice',
                'source_type' => 'sales_invoice',
                'source_id' => $invoice->id,
                'taxable' => $invoice->taxable,
                'inclusive_tax' => $invoice->inclusive_tax,
                'description' => __('Return claim #:id — :reason', ['id' => $claim->id, 'reason' => $claim->reason]),
                'created_by' => $actor->id,
            ]);
            foreach ($claim->lines()->with('invoiceLine')->get() as $i => $line) {
                $source = $line->invoiceLine;
                if ($source === null) {
                    throw new RuntimeException(__('A line points at a document line that does not exist.'));
                }
                $return->lines()->create([
                    'sort' => $i, 'item_id' => $line->item_id, 'quantity' => $line->quantity, 'unit_id' => $line->unit_id, 'base_quantity' => $line->base_quantity,
                    'unit_price' => $source->unit_price, 'discount_percent' => $source->discount_percent, 'tax_code_id' => $source->tax_code_id,
                    'warehouse_id' => $claim->warehouse_id, 'memo' => $source->memo,
                ]);
            }
            $return->refreshTotal();
            $this->documents->created($return);

            $claim->forceFill(['status' => ClaimStatus::VERIFIED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'sales_return_id' => $return->id])->saveQuietly();
            Auditor::log('claim_verified', $claim, null, ['return' => $return->number]);

            return $return->fresh();
        });
    }

    public function reject(ReturnClaim $claim, User $actor, string $note): void
    {
        $this->twoKeys->reject($claim, $actor, CentralScreen::ReturnClaims, $note);
    }

    /** How much of an invoice line may still come back, in base units: invoiced, less returned already, less other filed claims. */
    public function returnable(SalesInvoiceLine $line): BigDecimal
    {
        $returned = SalesReturnLine::query()
            ->whereHas('salesReturn', fn ($q) => $q->where('source_type', 'sales_invoice')->where('source_id', $line->sales_invoice_id))
            ->where('item_id', $line->item_id)->sum('base_quantity');
        $claimed = ReturnClaimLine::query()->where('sales_invoice_line_id', $line->id)
            ->whereHas('claim', fn ($q) => $q->where('status', ClaimStatus::FILED))->sum('base_quantity');

        return BigDecimal::of((string) $line->base_quantity)->minus((string) $returned)->minus((string) $claimed);
    }
}
