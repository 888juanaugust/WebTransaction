<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Regions\RegionContext;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * New orders are always created as drafts.
 *
 * Nothing is priced and no stock is held until somebody confirms — that is
 * what `confirmed` is for, and it is where the credit check and the stock
 * reservation live.
 */
class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['sales_user_id'] = auth()->id();

        // `status` is not set here on purpose. It is not fillable — only the
        // state machine may write it — and the model already defaults a new
        // order to draft. Assigning it here raises MassAssignmentException,
        // which is the guard doing its job.

        return $data;
    }

    /**
     * Created inside the customer's region, not the creator's.
     *
     * For pinned staff the two are the same thing. A global marketing has no
     * region of their own, so the order — and the document number it draws
     * from the per-region counter — files itself where the customer's books
     * are. The number is generated in here rather than in mutate…() because
     * the counter is region-scoped too.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $company = Company::query()->findOrFail($data['company_id']);

        return app(RegionContext::class)->within(
            (int) $company->region_id,
            function () use ($data) {
                $data['nomor'] = app(DocumentNumberGenerator::class)->nextOrderNumber();

                return parent::handleRecordCreation($data);
            },
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
