<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Models\Inventory\ItemTransfer;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receives (part of) a sent transfer: a receive document referencing the
 * send's lines, posted from In Transit into the destination; the send's
 * processed quantities (worked out by the fulfilment service from the lines
 * that name them) and status follow, and come back when a receipt goes.
 */
final class TransferReceiver
{
    public function __construct(private readonly DocumentRepository $documents, private readonly NumberGenerator $numbers) {}

    /** @param  array<int, string|int|float>  $quantities  send line id → base quantity received */
    public function receive(ItemTransfer $send, array $quantities, CarbonInterface $date, ?string $number = null, ?string $description = null): ItemTransfer
    {
        if (! $send->isSend()) {
            throw new RuntimeException(__('Only a sent transfer can be received.'));
        }

        return DB::transaction(function () use ($send, $quantities, $date, $number, $description): ItemTransfer {
            $send->load('lines');
            $series = $this->numbers->defaultSeries(TransactionType::ItemTransfer, auth()->user());

            $receive = ItemTransfer::query()->create([
                'number' => $number ?: $this->numbers->next($series, $date),
                'series_id' => $number ? null : $series?->id,
                'trans_date' => $date,
                'item_transfer_type' => ItemTransfer::RECEIVE,
                'warehouse_id' => $send->warehouse_id,
                'reference_warehouse_id' => $send->reference_warehouse_id,
                'reference_transfer_id' => $send->id,
                'branch_id' => $send->branch_id,
                'description' => $description ?? "Receipt of {$send->number}",
                'created_by' => auth()->id(),
            ]);

            $sort = 0;
            foreach ($send->lines as $line) {
                $qty = BigDecimal::of((string) ($quantities[$line->id] ?? 0));
                if (! $qty->isPositive()) {
                    continue;
                }
                $remaining = BigDecimal::of((string) $line->base_quantity)->minus((string) $line->processed_quantity);
                if ($qty->isGreaterThan($remaining)) {
                    throw new RuntimeException(__('Line :number: only :remaining left to receive.', ['number' => $line->item->number, 'remaining' => $remaining]));
                }
                $receive->lines()->create([
                    'sort' => $sort++,
                    'item_id' => $line->item_id,
                    'quantity' => (string) $qty,
                    'unit_id' => $line->item->unit1_id,
                    'base_quantity' => (string) $qty,
                    'memo' => $line->memo,
                    'source_line_type' => $line->getMorphClass(), // the send's processed quantities follow, as for every pulled line
                    'source_line_id' => $line->id,
                ]);
            }

            if ($receive->lines()->count() === 0) {
                throw new RuntimeException(__('Nothing to receive.'));
            }

            $this->documents->created($receive);
            $receive->refreshStatus();
            $send->refreshStatus();

            return $receive;
        });
    }
}
