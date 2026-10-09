<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Models\Company\Branch;
use App\Models\Settings\DocumentSeries;
use Carbon\CarbonImmutable;

/**
 * For a create page whose form holds NumberFields: draws the number from the
 * chosen series inside the save transaction, unless one was typed by hand.
 */
trait CreatesNumberedRecord
{
    abstract protected function transactionType(): TransactionType;

    protected function assignNumber(array $data): array
    {
        if (filled($data['number'] ?? null)) {
            unset($data['series_id']);

            return $data;
        }

        $series = DocumentSeries::query()->findOrFail($data['series_id'] ?? app(NumberGenerator::class)->defaultSeries($this->transactionType(), auth()->user())?->id);
        $date = isset($data['trans_date']) ? CarbonImmutable::parse($data['trans_date']) : CarbonImmutable::today();
        $branch = isset($data['branch_id']) ? Branch::query()->find($data['branch_id'])?->code : null; // none: numbered under the default branch
        $data['number'] = app(NumberGenerator::class)->next($series, $date, $branch);
        $data['series_id'] = $series->id;

        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->assignNumber($data);
    }
}
