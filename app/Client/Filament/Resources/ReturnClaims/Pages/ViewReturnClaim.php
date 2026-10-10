<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ReturnClaims\Pages;

use App\Client\Domain\Claims\ReturnClaims;
use App\Client\Domain\Claims\TwoKeys;
use App\Client\Domain\Stock\DamagedGoods;
use App\Client\Filament\Resources\ReturnClaims\ReturnClaimResource;
use App\Client\Screens\CentralScreen;
use App\Filament\Resources\Sales\SalesReturns\SalesReturnResource;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/** One return claim, its lines, and Inventory's key to post the return or to reject the claim. */
class ViewReturnClaim extends ViewRecord
{
    protected static string $resource = ReturnClaimResource::class;

    protected string $view = 'client.resources.return-claims.view';

    public function getTitle(): string
    {
        return __('Return claim #:id', ['id' => $this->record->id]);
    }

    public function mayDecide(): bool
    {
        return app(TwoKeys::class)->mayDecide($this->record, auth()->user(), CentralScreen::ReturnClaims);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Verify and post the return'))
                ->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => $this->mayDecide())
                ->modalHeading(__('Verify and post the return'))
                ->modalDescription(fn () => __('The goods are received in :warehouse and the sales return is made in your name.', ['warehouse' => $this->record->warehouse?->name]))
                ->fillForm(fn () => ['conditions' => $this->record->lines()->with('item')->get()->map(fn ($l) => ['line_id' => $l->id, 'item' => ($l->item?->number ?? '').' — '.($l->item?->name ?? ''), 'condition' => $l->condition])->all()])
                ->schema([
                    DatePicker::make('trans_date')->label(__('Return date'))->native(false)->required()->default(today()),
                    Repeater::make('conditions')->label(__('Condition of each line'))->schema([
                        Hidden::make('line_id'),
                        TextInput::make('item')->label(__('Item'))->disabled()->dehydrated(false)->columnSpan(2),
                        Select::make('condition')->label(__('Condition'))->options(DamagedGoods::conditionLabels())->required()->native(false),
                    ])->columns(3)->addable(false)->deletable(false)->reorderable(false),
                    Textarea::make('note')->label(__('fields.memo'))->rows(2),
                ])
                ->action(function (array $data): void {
                    $conditions = [];
                    foreach ((array) ($data['conditions'] ?? []) as $row) {
                        $conditions[(int) ($row['line_id'] ?? 0)] = (string) ($row['condition'] ?? DamagedGoods::GOOD);
                    }
                    try {
                        $return = app(ReturnClaims::class)->verify($this->record, auth()->user(), (string) $data['trans_date'], $data['note'] ?? null, $conditions);
                        Notification::make()->title(__('Sales return :number posted', ['number' => $return->number]))->success()->send();
                        $this->redirect(SalesReturnResource::getUrl('edit', ['record' => $return]));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot verify'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('reject')
                ->label(__('Reject'))
                ->icon('heroicon-m-x-circle')->color('danger')
                ->visible(fn () => $this->mayDecide())
                ->schema([Textarea::make('note')->label(__('Why'))->rows(3)->required()])
                ->action(function (array $data): void {
                    try {
                        app(ReturnClaims::class)->reject($this->record, auth()->user(), (string) $data['note']);
                        Notification::make()->title(__('Claim rejected'))->success()->send();
                        $this->redirect(ReturnClaimResource::getUrl('index'));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Cannot reject'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
