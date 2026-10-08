<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseInvoices\Pages;

use App\Domain\FixedAssets\AssetFromBill;
use App\Filament\Resources\FixedAssets\FixedAssets\FixedAssetResource;
use App\Filament\Resources\Purchasing\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Support\EditDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;

class EditPurchaseInvoice extends EditDocument
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordAsset')
                ->label(__('Record as fixed asset'))
                ->icon('heroicon-m-building-office')
                ->color('gray')
                ->visible(fn () => FixedAssetResource::canCreate() && AssetFromBill::openLines($this->record) !== [])
                ->schema([Select::make('line')->label(__('Invoice line'))->options(fn () => AssetFromBill::openLines($this->record))->required()->native(false)])
                ->action(fn (array $data) => $this->redirect(FixedAssetResource::getUrl('create', ['bill_line' => $data['line']]))),
            ...parent::getHeaderActions(),
        ];
    }
}
