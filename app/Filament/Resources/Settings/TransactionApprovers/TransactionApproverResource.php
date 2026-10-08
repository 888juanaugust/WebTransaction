<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settings\TransactionApprovers;

use App\Domain\Access\MenuKey;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Settings\TransactionApprovers\Pages\ManageTransactionApprovers;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\MasterResource;
use App\Filament\Support\PricedDocumentForm;
use App\Models\Company\TransactionApprover;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Transaction Approvers: which documents need approval, from whom, by whom, under which rule. Sales orders consult these when the Sales Order Approval rule is on. */
class TransactionApproverResource extends MasterResource
{
    protected static ?string $model = TransactionApprover::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $modelLabel = 'Approval rule';

    protected static ?string $recordTitleAttribute = 'transaction_type';

    public static function menuKey(): MenuKey
    {
        return MenuKey::TransactionApprovers;
    }

    /** @return array<string, string> the transaction types the approval engine knows, value → label */
    public static function documentOptions(): array
    {
        return collect(app(ApprovalEngine::class)->transactionTypes())->mapWithKeys(fn (TransactionType $type) => [$type->value => $type->getLabel()])->sort()->all();
    }

    /** @return array<string, string> slot key ("user:5", "group:2") → name, for the chosen approvers and groups */
    private static function slotOptions(callable $get): array
    {
        $users = User::query()->whereKey((array) $get('approvers'))->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => ["user:{$id}" => $name]);
        $groups = AccessGroup::query()->whereKey((array) $get('groups'))->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => ["group:{$id}" => __('Group: :name', ['name' => $name])]);

        return $users->merge($groups)->all();
    }

    /** Keeps the approval order in step with the chosen approvers: kept ones stay in place, new ones join at the end. */
    private static function syncOrder(Get $get, Set $set): void
    {
        $chosen = array_keys(self::slotOptions($get));
        $order = collect((array) $get('approval_order'))->pluck('slot')->filter(fn ($slot) => in_array($slot, $chosen, true))->values();
        foreach ($chosen as $slot) {
            if (! $order->contains($slot)) {
                $order->push($slot);
            }
        }
        $set('approval_order', $order->mapWithKeys(fn ($slot) => [(string) Str::uuid() => ['slot' => $slot]])->all());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('transaction_type')->label(__('Document'))->options(self::documentOptions())->required()->searchable()->native(false),
            PricedDocumentForm::money('min_amount', __('From amount'))->helperText(__('Documents below this amount need no approval.')),
            Select::make('rule')->label(__('Condition'))->options(TransactionApprover::rules())->default(TransactionApprover::ANY_ONE)->required()->native(false)->live(),
            Select::make('branch_id')->label(__('Branch'))->relationship('branch', 'name')->preload()->placeholder(__('Every branch'))->nullable()->native(false),
            Fieldset::make(__('Who needs approval'))->columns(1)->schema([
                Select::make('requesters')->label(__('Users'))->multiple()->relationship('requesters', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))
                    ->preload()->searchable()->helperText(__('Nobody chosen means every user.')),
            ]),
            Fieldset::make(__('Who approves'))->columns(1)->schema([
                Select::make('groups')->label(__('Access groups'))->multiple()->relationship('groups', 'name', fn ($query) => $query->orderBy('name'))->preload()
                    ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncOrder($get, $set))
                    ->helperText(__('Any one member of a group fills that group\'s place.')),
                Select::make('approvers')->label(__('Users'))->multiple()->relationship('approvers', 'name', fn ($query) => $query->where('is_active', true)->orderBy('name'))->preload()->searchable()
                    ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::syncOrder($get, $set)),
                // Saved after the two lists above: the order is the pivots' sort.
                Repeater::make('approval_order')
                    ->label(__('Approval order'))
                    ->schema([Select::make('slot')->hiddenLabel()->options(fn (Get $get) => self::slotOptions(fn ($path) => $get('../../'.$path)))->disabled()->dehydrated()])
                    ->addable(false)->deletable(false)->reorderable()->reorderableWithButtons()
                    ->visible(fn (Get $get) => $get('rule') === TransactionApprover::IN_ORDER)
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Repeater $component, ?TransactionApprover $record): void {
                        if ($record === null) {
                            return;
                        }
                        $slots = $record->approvers()->get()->map(fn ($u) => ['slot' => "user:{$u->id}", 'sort' => (int) $u->pivot->sort])
                            ->concat($record->groups()->get()->map(fn ($g) => ['slot' => "group:{$g->id}", 'sort' => (int) $g->pivot->sort]))
                            ->sortBy('sort')->values();
                        $component->state($slots->mapWithKeys(fn ($s) => [(string) Str::uuid() => ['slot' => $s['slot']]])->all());
                    })
                    ->saveRelationshipsUsing(function (TransactionApprover $record, ?array $state): void {
                        foreach (array_values((array) $state) as $sort => $row) {
                            [$kind, $id] = explode(':', (string) ($row['slot'] ?? ':'), 2) + [null, null];
                            match ($kind) {
                                'user' => $record->approvers()->updateExistingPivot((int) $id, ['sort' => $sort]),
                                'group' => $record->groups()->updateExistingPivot((int) $id, ['sort' => $sort]),
                                default => null,
                            };
                        }
                    }),
            ]),
            self::activeToggle(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['groups', 'approvers', 'requesters', 'branch']))
            ->columns([
                TextColumn::make('transaction_type')->label(__('Document'))->sortable()
                    ->formatStateUsing(fn (string $state): string => TransactionType::tryFrom($state)?->getLabel() ?? $state),
                Rupiah::make('min_amount')->label(__('From amount')),
                TextColumn::make('approved_by')->label(__('Approved by'))->limit(60)
                    ->state(fn ($record): string => $record->groups->pluck('name')->merge($record->approvers->pluck('name'))->join(', ')),
                TextColumn::make('requested_by')->label(__('Requested by'))->limit(60)
                    ->state(fn ($record): string => $record->requesters->isEmpty() ? 'Everyone' : $record->requesters->pluck('name')->join(', ')),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder(__('Every branch')),
                TextColumn::make('rule')->label(__('Condition'))
                    ->formatStateUsing(fn (string $state): string => TransactionApprover::rules()[$state] ?? $state),
                self::activeColumn(),
            ])
            ->defaultSort('transaction_type')
            ->filters([
                self::activeFilter(),
                SelectFilter::make('transaction_type')->label(__('Document'))->options(self::documentOptions()),
                SelectFilter::make('branch_id')->label(__('Branch'))->relationship('branch', 'name'),
            ])
            ->recordActions([EditAction::make()->slideOver(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTransactionApprovers::route('/')];
    }
}
