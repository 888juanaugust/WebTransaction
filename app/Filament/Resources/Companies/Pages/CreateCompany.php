<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Domain\Access\TeamAssigner;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Create the customer, then seat the team through TeamAssigner — same
     * split as EditCompany, so a brand-new customer's first assignment is
     * audited and validated exactly like a change to an old one.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $salesId = $data['sales_user_id'] ?? null;
        $marketingId = $data['marketing_user_id'] ?? null;
        unset($data['sales_user_id'], $data['marketing_user_id']);

        /** @var Company $company */
        $company = static::getModel()::create($data);

        $assigner = app(TeamAssigner::class);
        $actor = auth()->user();

        if ($salesId) {
            $assigner->assignSales($company, User::find((int) $salesId), $actor);
        }

        if ($marketingId) {
            $assigner->assignMarketing($company, User::find((int) $marketingId), $actor);
        }

        return $company;
    }
}
