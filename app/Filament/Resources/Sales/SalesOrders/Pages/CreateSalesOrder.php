<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesOrders\Pages;

use App\Domain\Numbering\TransactionType;
use App\Domain\Sales\OrderApproval;
use App\Filament\Resources\Sales\SalesOrders\SalesOrderResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\PrefillsFromSource;
use App\Filament\Support\PricedDocumentForm;
use App\Filament\Support\TagFields;
use App\Models\Sales\SalesQuotation;
use Illuminate\Database\Eloquent\Model;

class CreateSalesOrder extends CreateDocument
{
    use PrefillsFromSource;

    protected static string $resource = SalesOrderResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::SalesOrder;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['approval_status'] = app(OrderApproval::class)->initialStatus();

        return parent::mutateFormDataBeforeCreate($data);
    }

    protected function sourceModel(): string
    {
        return SalesQuotation::class;
    }

    protected function dataFromSource(Model $source): array
    {
        return [
            'customer_id' => $source->customer_id,
            'taxable' => $source->taxable,
            'inclusive_tax' => $source->inclusive_tax,
            'payment_term_id' => $source->payment_term_id,
            'to_address' => $source->to_address,
            'discount_percent' => (string) $source->discount_percent,
            'description' => "From quotation {$source->number}",
            ...TagFields::from($source),
            'lines' => PricedDocumentForm::pulledLines($source->lines()->with('item')->get(), 'sales_quotation_line'),
        ];
    }
}
