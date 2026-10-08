<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\Accounts\Pages;

use App\Domain\GeneralLedger\AccountOpenings;
use App\Filament\Resources\GeneralLedger\Accounts\AccountResource;
use App\Filament\Support\ManageMaster;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\AccountOpeningBalance;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The opening balance is not a column: it is a posted document of its own, kept in step here. */
class ManageAccounts extends ManageMaster
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New account'))->slideOver()
                ->mutateDataUsing(fn (array $data) => self::liftOpening($data))
                ->after(fn (Model $record, CreateAction $action) => self::saveOpening($record, $action)),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->recordActions([
            EditAction::make()->slideOver()
                ->mutateRecordDataUsing(function (array $data, Account $record): array {
                    $opening = AccountOpeningBalance::query()->where('account_id', $record->id)->first();
                    $data['opening_amount'] = $opening?->amount;
                    $data['opening_date'] = $opening?->trans_date?->toDateString();

                    return $data;
                })
                ->mutateDataUsing(fn (array $data) => self::liftOpening($data))
                ->after(fn (Model $record, EditAction $action) => self::saveOpening($record, $action)),
            DeleteAction::make()->hidden(fn (Account $r) => $r->is_system),
        ]);
    }

    private static array $opening = [];

    private static function liftOpening(array $data): array
    {
        self::$opening = ['amount' => $data['opening_amount'] ?? null, 'date' => $data['opening_date'] ?? null];
        unset($data['opening_amount'], $data['opening_date']);

        return $data;
    }

    /** The opening balance is saved with the account; when it is refused, so is the account change. */
    private static function saveOpening(Model $record, Action $action): void
    {
        try {
            app(AccountOpenings::class)->save($record, self::$opening['amount'] ?? null, self::$opening['date'] ?? null);
        } catch (\RuntimeException $e) {
            Notification::make()->title(__('Opening balance not saved'))->body($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }
    }
}
