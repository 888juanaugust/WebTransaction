<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Quotes\QuotationFlow;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Creation goes through QuotationFlow, never through the model: the flow
 * is where the lines get priced by the one resolver and the audit row is
 * written. A quote made any other way would be a price somebody typed.
 */
class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(QuotationFlow::class)->draft(
            company: Company::query()->findOrFail($data['company_id']),
            lines: array_map(fn (array $baris) => [
                'sku' => $baris['sku'],
                'qty' => (int) $baris['qty'],
                'unit' => $baris['unit'],
            ], array_values($data['baris'] ?? [])),
            actor: auth()->user(),
            validUntil: Carbon::parse($data['valid_until']),
            catatan: $data['catatan'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
