<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuRegistry;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Currency\Currencies;
use App\Domain\Shared\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The standard's "Ambil": pick open upstream documents of the chosen
 * party and append their remaining lines to the grid, each line pointing at
 * its source so fulfilment follows. Documents waiting for approval, or in
 * another currency, are not offered.
 */
final class PullAction
{
    /**
     * @param  Closure(Get): iterable  $documents  the open upstream documents for the current form state (number + id)
     * @param  Closure(int): array  $lines  the pulled rows of one upstream document (PricedDocumentForm::pulledLines)
     */
    public static function make(string $label, string $partyField, Closure $documents, Closure $lines): Action
    {
        return Action::make('pull')
            ->label($label)
            ->icon('heroicon-m-arrow-down-tray')
            ->color('gray')
            ->visible(fn (Get $get) => (bool) $get($partyField))
            ->schema(fn (Get $get) => [
                CheckboxList::make('sources')
                    ->label(__('Open documents'))
                    ->options(self::open($documents, $get)->mapWithKeys(fn ($doc) => [$doc->id => $doc->number.' · '.Format::date($doc->trans_date).($doc->description ? " · {$doc->description}" : '')])->all())
                    ->required()
                    ->bulkToggleable(),
            ])
            ->action(function (Set $set, Get $get, array $data) use ($documents, $lines): void {
                $existing = array_filter((array) $get('lines'), fn ($l) => ! empty($l['item_id']));
                $added = 0;
                $offered = self::open($documents, $get)->map(fn ($doc) => (int) $doc->id)->all();
                foreach (array_intersect(array_map('intval', (array) ($data['sources'] ?? [])), $offered) as $id) {
                    foreach ($lines((int) $id) as $row) {
                        $existing[(string) Str::uuid()] = $row;
                        $added++;
                    }
                }
                $set('lines', $existing);
                Notification::make()->title($added ? __(':count line(s) pulled', ['count' => $added]) : __('Nothing left to pull'))->success()->send();
            });
    }

    /** The documents offered: approved, in the form's currency, and ones the user may see (their branches, the view right on the screen). */
    private static function open(Closure $documents, Get $get): Collection
    {
        $user = auth()->user();

        return collect($documents($get))->filter(function (Model $doc) use ($get, $user): bool {
            $screen = app(MenuRegistry::class)->menuKeyForModel($doc::class);
            $branch = $doc->getAttribute('branch_id');

            return app(ApprovalEngine::class)->isApproved($doc) && self::sameCurrency($doc, $get('currency_id'))
                && BranchLimit::allows($user, $branch !== null ? (int) $branch : null)
                && ($screen === null || app(HakAkses::class)->allows($user, $screen, Hak::View));
        })->values();
    }

    /** Lines are pulled only from documents in the form's currency (a document without one, such as a requisition, is priced in neither). */
    private static function sameCurrency(Model $document, mixed $currencyId): bool
    {
        return ! array_key_exists('currency_id', $document->getAttributes()) || Currencies::same($document->getAttribute('currency_id'), $currencyId);
    }
}
