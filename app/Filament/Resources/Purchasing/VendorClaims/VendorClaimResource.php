<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorClaims;

use App\Domain\Access\MenuKey;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\VendorClaims\Pages\CreateVendorClaim;
use App\Filament\Resources\Purchasing\VendorClaims\Pages\EditVendorClaim;
use App\Filament\Resources\Purchasing\VendorClaims\Pages\ListVendorClaims;
use App\Filament\Support\ApprovalActions;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\DocumentListFilters;
use App\Filament\Support\ErpResource;
use App\Filament\Support\LineItemFields;
use App\Filament\Support\NumberFields;
use App\Filament\Support\TagFields;
use App\Filament\Support\VendorFields;
use App\Models\Purchasing\VendorClaim;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Vendor Claims: goods sent to or received back from a vendor under warranty or claim, without a sale or purchase. */
class VendorClaimResource extends ErpResource
{
    protected static ?string $model = VendorClaim::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'Vendor claim';

    protected static ?string $recordTitleAttribute = 'number';

    public static function menuKey(): MenuKey
    {
        return MenuKey::VendorClaims;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('claim_type')->label(__('Claim type'))->options(['send' => __('Send goods to the vendor'), 'receive' => __('Receive goods from the vendor')])->default('send')->required()->native(false),
                VendorFields::select(),
                NumberFields::make(TransactionType::VendorClaim, __('Claim No.')),
                DatePicker::make('trans_date')->label(__('fields.trans_date'))->required()->native(false)->default(today()),
            ]),
            Tabs::make('claim')->tabs([
                Tab::make(__('fields.lines'))->schema([
                    Repeater::make('lines')->label(__('fields.lines'))
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort')
                        ->table([TableColumn::make(__('Item')), TableColumn::make(__('Quantity'))->alignment(Alignment::End), TableColumn::make(__('Unit')), ...TagFields::columns(), TableColumn::make(__('Memo'))])
                        ->schema([
                            LineItemFields::item(groups: false),
                            LineItemFields::quantity()->minValue(0.0001),
                            LineItemFields::unit(),
                            ...TagFields::lineFields(),
                            TextInput::make('memo')->label(__('Memo'))->maxLength(255),
                            LineItemFields::baseQuantity(),
                        ])
                        ->minItems(1)->defaultItems(1)->addActionLabel(__('Add line'))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0])
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => LineItemFields::fillBaseQuantities([$data])[0]),
                ]),
                Tab::make(__('fields.other_info'))->schema([
                    ...TagFields::header(),
                    Textarea::make('to_address')->label(__('Vendor\'s address'))->rows(2),
                    Textarea::make('description')->label(__('fields.description'))->rows(2),
                ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('vendor'))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->sortable()->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('claim_type')->label(__('Claim type'))->badge()->color('gray')->formatStateUsing(fn (string $state) => $state === 'send' ? 'Send goods' : 'Receive goods'),
                TextColumn::make('vendor.name')->label(__('fields.vendor'))->searchable(),
                TextColumn::make('description')->label(__('fields.description'))->limit(40)->placeholder('—'),
                TextColumn::make('status')->label(__('Delivery status'))->badge()->formatStateUsing(fn (string $state) => __('status.fulfilment.'.$state))
                    ->color(fn (string $state) => $state === 'processed' ? 'success' : 'info'),
                ApprovalActions::column(),
            ])
            ->defaultSort('trans_date', 'desc')
            ->filters([
                DocumentListFilters::dateRange(),
                SelectFilter::make('status')->label(__('Claim status'))->options(['pending' => __('Pending'), 'processed' => __('Settled')]),
                SelectFilter::make('claim_type')->label(__('Claim type'))->options(['send' => __('Send goods'), 'receive' => __('Receive goods')]),
                SelectFilter::make('vendor_id')->label(__('fields.vendor'))->relationship('vendor', 'name')->searchable(),
            ])
            ->recordActions([
                ...ApprovalActions::make(),
                EditAction::make(),
                Action::make('settle')->label(__('Mark settled'))->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                    ->visible(fn (VendorClaim $record) => $record->status === 'pending' && static::canEdit($record))
                    ->action(function (VendorClaim $record): void {
                        $record->update(['status' => 'processed']);
                        Auditor::log('updated', $record, $record->number, ['before' => ['status' => 'pending'], 'after' => ['status' => 'processed']]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorClaims::route('/'),
            'create' => CreateVendorClaim::route('/create'),
            'edit' => EditVendorClaim::route('/{record}/edit'),
        ];
    }
}
