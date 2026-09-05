<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Domain\Access\TeamAssigner;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Filament's DeleteAction authorises nothing on its own, so the
             * resource's canDelete() has to be asked here or it is a comment.
             * Measured before this line: a sales rep pressed this button and
             * the customer went — approval, credit limit, NPWP and assigned
             * seats with it.
             */
            DeleteAction::make()
                ->visible(fn (Company $record) => CompanyResource::canDelete($record)),
        ];
    }

    /**
     * The team seats go through TeamAssigner, everything else saves as before.
     *
     * The assigner checks the seat's role, activity and region, and writes the
     * audit entry — "who was this customer's marketing at the time" is the
     * question a disputed approval traces back to, so the answer is recorded
     * as it changes. The two keys are pulled out of the payload so the plain
     * save cannot write the columns (they are not fillable either — two
     * layers, same rule).
     *
     * Filament omits disabled fields from the payload, which is exactly the
     * non-owner case: array_key_exists keeps their save a no-op on the team.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $record */
        $assigner = app(TeamAssigner::class);
        $actor = auth()->user();

        if (array_key_exists('sales_user_id', $data)) {
            $sales = $data['sales_user_id'] ? User::find((int) $data['sales_user_id']) : null;
            $assigner->assignSales($record, $sales, $actor);
            unset($data['sales_user_id']);
        }

        if (array_key_exists('marketing_user_id', $data)) {
            $marketing = $data['marketing_user_id'] ? User::find((int) $data['marketing_user_id']) : null;
            $assigner->assignMarketing($record, $marketing, $actor);
            unset($data['marketing_user_id']);
        }

        $record->update($data);

        return $record->refresh();
    }
}
