<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ReturnClaims;

use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Domain\Claims\ReturnClaims;
use App\Client\Filament\Resources\ReturnClaims\Pages\CreateReturnClaim;
use App\Client\Filament\Resources\ReturnClaims\Pages\ListReturnClaims;
use App\Client\Filament\Resources\ReturnClaims\Pages\ViewReturnClaim;
use App\Client\Models\ReturnClaim;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceLine;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Return Claims: the sales seat's word that goods of an invoice come back, awaiting Inventory's key. */
class ReturnClaimResource extends ErpResource
{
    protected static ?string $model = ReturnClaim::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $modelLabel = 'Return claim';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::ReturnClaims;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The invoice'))->columns(2)->schema([
                Select::make('customer_id')->label(__('fields.customer'))
                    ->options(fn () => self::customers()->pluck('name', 'id'))
                    ->searchable()->required()->native(false)->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $set('sales_invoice_id', null);
                        $set('lines', []);
                        $set('warehouse_id', Customer::query()->find($state)?->default_warehouse_id);
                    }),
                Select::make('sales_invoice_id')->label(__('Invoice'))
                    ->options(fn (Get $get) => self::invoices((int) $get('customer_id')))
                    ->searchable()->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set) => $set('lines', [])),
                Select::make('warehouse_id')->label(__('Back to warehouse'))
                    ->options(fn () => Warehouse::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required()->native(false),
                Textarea::make('reason')->label(__('Reason'))->rows(2)->required(),
            ]),
            Section::make(__('What comes back'))->schema([
                Repeater::make('lines')->label(__('Lines'))->schema([
                    Select::make('sales_invoice_line_id')->label(__('Invoice line'))
                        ->options(fn (Get $get) => self::invoiceLines((int) $get('../../sales_invoice_id')))
                        ->required()->native(false)->distinct()->columnSpan(2),
                    TextInput::make('quantity')->label(__('fields.quantity'))->numeric()->minValue(0.0001)->required(),
                ])->columns(3)->minItems(1)->addActionLabel(__('Add a line'))->reorderable(false),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'invoice', 'warehouse', 'filedBy', 'decidedBy'])->withCount('lines'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('invoice.number')->label(__('Invoice'))->searchable()->fontFamily('mono'),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable()->weight('medium'),
                TextColumn::make('warehouse.name')->label(__('Warehouse')),
                TextColumn::make('lines_count')->label(__('Lines'))->alignEnd(),
                TextColumn::make('reason')->label(__('Reason'))->limit(40)->wrap(),
                TextColumn::make('filedBy.name')->label(__('Filed by')),
                Tanggal::make('created_at')->label(__('Filed'))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => ClaimStatus::label($state))
                    ->color(fn (string $state) => ClaimStatus::color($state)),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    ClaimStatus::FILED => ClaimStatus::label(ClaimStatus::FILED),
                    ClaimStatus::VERIFIED => ClaimStatus::label(ClaimStatus::VERIFIED),
                    ClaimStatus::REJECTED => ClaimStatus::label(ClaimStatus::REJECTED),
                ])->default(ClaimStatus::FILED),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No return claims'))
            ->emptyStateDescription(__('The sales seat files which goods come back; Inventory verifies the claim into a sales return.'));
    }

    /** The customers this user files for: their own as the sales seat, every active one for an administrator. */
    public static function customers(): Collection
    {
        $user = auth()->user();
        $query = BranchLimit::apply(Customer::query()->where('is_active', true)->orderBy('name'), $user);
        if ($user !== null && ! $user->isAdministrator()) {
            $query->where('sales_user_id', $user->id);
        }

        return $query->get();
    }

    /** @return array<int, string> */
    public static function invoices(int $customerId): array
    {
        if ($customerId === 0) {
            return [];
        }
        $out = [];
        foreach (SalesInvoice::query()->where('customer_id', $customerId)->orderByDesc('trans_date')->limit(100)->get() as $invoice) {
            if (app(ApprovalEngine::class)->isApproved($invoice)) {
                $out[$invoice->id] = "{$invoice->number} · ".Format::date($invoice->trans_date).' · '.Format::money((int) $invoice->total);
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function invoiceLines(int $invoiceId): array
    {
        if ($invoiceId === 0) {
            return [];
        }
        $out = [];
        foreach (SalesInvoiceLine::query()->where('sales_invoice_id', $invoiceId)->with(['item', 'unit'])->orderBy('sort')->get() as $line) {
            $room = app(ReturnClaims::class)->returnable($line);
            $out[$line->id] = ($line->item?->name ?? '').' · '.Format::quantity((string) $line->quantity).' '.($line->unit?->name ?? '').' · '.__(':n may come back', ['n' => Format::quantity($room->__toString())]);
        }

        return $out;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReturnClaims::route('/'),
            'create' => CreateReturnClaim::route('/create'),
            'view' => ViewReturnClaim::route('/{record}'),
        ];
    }
}
