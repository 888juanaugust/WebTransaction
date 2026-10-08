<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FixedAssets\Pages;

use App\Domain\FixedAssets\AssetFromBill;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\FixedAssets\FixedAssets\FixedAssetResource;
use App\Filament\Support\CreateDocument;
use App\Filament\Support\DocumentPages;
use App\Filament\Support\SourceDocument;
use App\Models\FixedAssets\FixedAsset;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseInvoiceLine;

/** Numbered from the asset-code series; saving posts the acquisition. Opened with ?bill_line=ID it starts from that purchase invoice line. */
class CreateFixedAsset extends CreateDocument
{
    protected static string $resource = FixedAssetResource::class;

    public function mount(): void
    {
        parent::mount();
        $line = PurchaseInvoiceLine::query()->find(request()->integer('bill_line'));
        // Only a line of a bill the user may see (their branches, the view right, approved).
        $line = $line !== null && SourceDocument::check(PurchaseInvoice::query()->find($line->purchase_invoice_id)) !== null ? $line : null;
        if ($line !== null && ! FixedAsset::query()->where('purchase_invoice_line_id', $line->id)->exists()) {
            $data = AssetFromBill::prefill($line);
            $expenditures = $data['expenditures'];
            unset($data['expenditures']);
            $this->form->fill(array_merge($this->form->getRawState(), $data));
            $this->data['expenditures'] = DocumentPages::keyedRows($expenditures);
        }
    }

    protected function transactionType(): TransactionType
    {
        return TransactionType::FixedAsset;
    }
}
