<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SettlementClaims;

use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Domain\Teams\TeamAssigner;
use App\Client\Filament\Resources\SettlementClaims\Pages\CreateSettlementClaim;
use App\Client\Filament\Resources\SettlementClaims\Pages\ListSettlementClaims;
use App\Client\Filament\Resources\SettlementClaims\Pages\ViewSettlementClaim;
use App\Client\Models\SettlementClaim;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Shared\Format;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Filament\Support\ErpResource;
use App\Filament\Support\MoneyInput;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

/** Settlement Claims: a seat's word that a customer paid an invoice, awaiting Finance's key. */
class SettlementClaimResource extends ErpResource
{
    protected static ?string $model = SettlementClaim::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $modelLabel = 'Settlement claim';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::SettlementClaims;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The invoice'))->schema([
                Select::make('customer_id')->label(__('fields.customer'))
                    ->options(fn () => self::customers()->pluck('name', 'id'))
                    ->searchable()->required()->native(false)->live()
                    ->afterStateUpdated(fn (Set $set) => $set('sales_invoice_id', null)),
                Select::make('sales_invoice_id')->label(__('Invoice'))
                    ->options(fn (Get $get) => self::openInvoices((int) $get('customer_id')))
                    ->searchable()->required()->native(false)->live()
                    ->helperText(__('Only approved invoices with a balance.')),
                MoneyInput::make('amount')->label(__('Amount received'))->prefix(Format::symbol())->required()
                    ->helperText(fn (Get $get) => ($id = $get('sales_invoice_id')) && ($inv = SalesInvoice::query()->find($id)) ? __('Balance: :amount', ['amount' => Format::money($inv->balance())]) : null),
                Textarea::make('account')->label(__('How the money was handed over'))->rows(3)->required()
                    ->helperText(__('Where, when and how: a transfer on which date, cash to whom, a giro and its number.')),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'invoice', 'filedBy', 'decidedBy']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('invoice.number')->label(__('Invoice'))->searchable()->fontFamily('mono'),
                TextColumn::make('customer.name')->label(__('fields.customer'))->searchable()->weight('medium'),
                Rupiah::make('amount')->label(__('Amount')),
                TextColumn::make('filedBy.name')->label(__('Filed by')),
                Tanggal::make('created_at')->label(__('Filed'))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => ClaimStatus::label($state))
                    ->color(fn (string $state) => ClaimStatus::color($state)),
                TextColumn::make('decidedBy.name')->label(__('Decided by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    ClaimStatus::FILED => ClaimStatus::label(ClaimStatus::FILED),
                    ClaimStatus::VERIFIED => ClaimStatus::label(ClaimStatus::VERIFIED),
                    ClaimStatus::REJECTED => ClaimStatus::label(ClaimStatus::REJECTED),
                ])->default(ClaimStatus::FILED),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No settlement claims'))
            ->emptyStateDescription(__('A seat files one when a customer has paid an invoice; Finance verifies it into a receipt.'));
    }

    /** The customers this user files for: their own for a seat, every active one for an administrator. */
    public static function customers(): Collection
    {
        $user = auth()->user();
        $query = BranchLimit::apply(Customer::query()->where('is_active', true)->orderBy('name'), $user);
        if ($user !== null && ! $user->isAdministrator()) {
            $query->where(fn (Builder $q) => $q->where('sales_user_id', $user->id)->orWhere('marketing_user_id', $user->id));
        }

        return $query->get();
    }

    /** @return array<int, string> */
    public static function openInvoices(int $customerId): array
    {
        if ($customerId === 0) {
            return [];
        }
        $out = [];
        foreach (SalesInvoice::query()->where('customer_id', $customerId)->where('payment_status', '!=', 'paid')->orderBy('trans_date')->get() as $invoice) {
            if ($invoice->balance() > 0 && app(ApprovalEngine::class)->isApproved($invoice)) {
                $out[$invoice->id] = "{$invoice->number} · ".Format::date($invoice->trans_date).' · '.Format::money($invoice->balance());
            }
        }

        return $out;
    }

    public static function mayFileFor(Customer $customer): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdministrator() || TeamAssigner::holdsSeat($user, $customer));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettlementClaims::route('/'),
            'create' => CreateSettlementClaim::route('/create'),
            'view' => ViewSettlementClaim::route('/{record}'),
        ];
    }
}
