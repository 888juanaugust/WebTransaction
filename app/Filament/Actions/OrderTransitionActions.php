<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Domain\Access\Role;
use App\Domain\Money;
use App\Domain\Orders\OrderEraser;
use App\Domain\Orders\OrderSplitter;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\InsufficientStockException;
use App\Models\Order;
use App\Models\Warehouse;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Every order transition, in one place, for every screen that offers one.
 *
 * These used to live inline on the dashboard widgets, which meant the queues
 * could approve and ship an order but the order's own list and detail page
 * could not — you could open an order, read it, and have no way to act on it.
 * Copying the actions onto those pages would have put the same state-machine
 * call in three files, and a fix to one would have missed the others.
 *
 * Each action here is the same shape: check the state, check the role, call the
 * state machine, report what happened. None of them writes `status` — that is
 * the state machine's job alone, and it logs an event with an actor for every
 * move.
 */
class OrderTransitionActions
{
    /**
     * Mirrors OrderStateMachine::assertMayApprove — the button only shows
     * when the click would succeed. The machine still enforces it, so the
     * two cannot drift apart in a way that matters; they can only drift in
     * a way that shows a button which then refuses politely.
     */
    private static function holdsApprovalSeat(Order $record): bool
    {
        $user = auth()->user();

        if ($user === null || ! $user->role()->canApproveOrders()) {
            return false;
        }

        return $user->role() !== Role::Marketing
            || (int) $record->company->marketing_user_id === (int) $user->getKey();
    }

    /**
     * Approve. Prices snapshot, credit is checked, stock is reserved — one
     * transaction, and any of the three can refuse the whole thing.
     */
    public static function setujui(string $name = 'setujui'): Action
    {
        return Action::make($name)
            ->label('Setujui')
            ->icon('heroicon-o-check-circle')
            // Navy: this is the ordinary forward action, not a celebration.
            // Green stays reserved for settled money.
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Setujui order')
            /*
             * When the goods are scattered, the approver is told *before*
             * the click that this will become several transactions and
             * where each ships from — a split that surprises marketing is
             * a phone call from a customer holding two fakturs.
             */
            ->modalDescription(function (Order $record) {
                $dasar = 'Menyetujui akan mengunci harga dan memesan stok untuk '
                    .$record->company->nama.'.';

                try {
                    $plan = app(OrderSplitter::class)->plan($record);
                } catch (InsufficientStockException) {
                    return $dasar.' Perhatian: stok tidak cukup di seluruh gudang — persetujuan akan ditolak.';
                }

                if (count($plan) <= 1 && array_key_exists((int) $record->warehouse_id, $plan)) {
                    return $dasar;
                }

                $gudang = Warehouse::query()
                    ->withoutGlobalScope('region')
                    ->findMany(array_keys($plan))
                    ->keyBy('id');

                $rincian = collect($plan)
                    ->map(fn (array $shares, int $warehouseId) => $gudang[$warehouseId]->kode
                        .' ('.collect($shares)->map(fn ($s) => "{$s['sku']}×{$s['qty_base']}")->implode(', ').')')
                    ->implode('; ');

                return $dasar.' Stok tersebar: order akan dipecah menjadi '
                    .count($plan).' transaksi per gudang — '.$rincian.'.';
            })
            ->visible(fn (Order $record) => $record->status === OrderStatus::Submitted
                && static::holdsApprovalSeat($record))
            ->action(function (Order $record) {
                try {
                    app(OrderStateMachine::class)->confirm($record, auth()->user());

                    Notification::make()
                        ->title("Order {$record->nomor} dikonfirmasi")
                        ->body('Harga terkunci dan stok dipesan.')
                        ->success()
                        ->send();
                } catch (InsufficientStockException $e) {
                    Notification::make()
                        ->title('Stok tidak cukup')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa dikonfirmasi')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Reject, with a reason.
     *
     * The reason is required because it is what the customer is told, and
     * because a rejected order with no explanation is a support call later.
     * Rejecting a confirmed order hands its reserved stock back.
     */
    public static function tolak(string $name = 'tolak'): Action
    {
        return Action::make($name)
            ->label('Tolak')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Tolak order')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan penolakan')
                    ->helperText('Alasan ini tercatat di riwayat order.')
                    ->required()
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record) => $record->status->canTransitionTo(OrderStatus::Rejected)
                && static::holdsApprovalSeat($record))
            ->action(function (Order $record, array $data) {
                try {
                    app(OrderStateMachine::class)->reject($record, auth()->user(), $data['alasan']);

                    Notification::make()
                        ->title("Order {$record->nomor} ditolak")
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa ditolak')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Erase an unfinished order — marketing clearing the clutter.
     *
     * Not a transition: draft and submitted orders hold nothing (no reserved
     * stock, no snapshotted prices, no invoice), so they may simply go.
     * OrderEraser enforces the same seat rule and writes the audit snapshot
     * that outlives the row.
     */
    public static function hapus(string $name = 'hapus'): Action
    {
        return Action::make($name)
            ->label('Hapus')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('Hapus order yang belum jadi')
            ->modalDescription(fn (Order $record) => "Order {$record->nomor} untuk {$record->company->nama} akan dihapus. "
                .'Jejaknya tetap tercatat di log audit.')
            ->schema([
                Textarea::make('alasan')
                    ->label('Alasan')
                    ->helperText('Kenapa order ini dihapus — tercatat di log audit.')
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Draft, OrderStatus::Submitted], true)
                && static::holdsApprovalSeat($record))
            ->action(function (Order $record, array $data) {
                try {
                    app(OrderEraser::class)->erase($record, auth()->user(), $data['alasan'] ?? null);

                    Notification::make()
                        ->title("Order {$record->nomor} dihapus")
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa dihapus')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Bill the customer: issue the invoice and make sure they have a virtual
     * account to pay into.
     *
     * Gated on seeing credit data rather than on creating orders — this is the
     * step that turns a held order into money owed, which is finance's call.
     */
    public static function tagihkan(string $name = 'tagihkan'): Action
    {
        return Action::make($name)
            ->label('Tagihkan')
            ->icon('heroicon-o-document-currency-dollar')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Terbitkan faktur')
            ->modalDescription(fn (Order $record) => 'Faktur akan diterbitkan sebesar '
                .Money::format($record->total_rupiah)
                .' dan pelanggan akan diberi nomor Virtual Account untuk pembayaran.')
            ->visible(fn (Order $record) => $record->status === OrderStatus::Confirmed
                && (auth()->user()?->role()->canSeeCreditData() ?? false))
            ->action(function (Order $record) {
                try {
                    app(OrderStateMachine::class)->awaitPayment($record, auth()->user());
                    $record->refresh();

                    Notification::make()
                        ->title("Faktur {$record->invoice->nomor} diterbitkan")
                        ->body('Jatuh tempo '.$record->invoice->due_date->format('d/m/Y'))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa ditagihkan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Ship: the held reservations become real decrements in the stock ledger.
     *
     * Warehouse work, and the only transition they own. Deliberately available
     * from the order list and the pick list alike — a packer finishing a box
     * should not have to navigate back to a dashboard to say so.
     */
    public static function kirim(string $name = 'kirim'): Action
    {
        return Action::make($name)
            ->label('Tandai dikirim')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Tandai sudah dikirim')
            ->modalDescription('Stok akan dikurangi dari gudang. Tindakan ini tercatat di kartu stok.')
            /*
             * Awaiting payment is the credit-sales path and the normal one
             * now: the goods leave on the marketing's approval, and the
             * invoice stands as debt until the money arrives on terms.
             */
            ->visible(fn (Order $record) => in_array($record->status, [OrderStatus::Paid, OrderStatus::AwaitingPayment], true)
                && (auth()->user()?->role()->canPickAndShip() ?? false))
            ->action(function (Order $record) {
                try {
                    app(OrderStateMachine::class)->ship($record, auth()->user());

                    Notification::make()
                        ->title("Order {$record->nomor} dikirim")
                        ->body('Stok sudah dikurangi.')
                        ->success()
                        ->send();
                } catch (DomainException|\LogicException $e) {
                    Notification::make()
                        ->title('Tidak bisa dikirim')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /** Close the order once the customer has the goods. */
    public static function selesaikan(string $name = 'selesaikan'): Action
    {
        return Action::make($name)
            ->label('Selesaikan')
            ->icon('heroicon-o-check-badge')
            // The one green action: a completed order is settled, and finance
            // scans for exactly this.
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Selesaikan order')
            ->modalDescription('Tandai order ini selesai. Barang sudah diterima pelanggan.')
            ->visible(fn (Order $record) => $record->status === OrderStatus::Shipped
                && (auth()->user()?->role()->canPickAndShip() ?? false))
            ->action(function (Order $record) {
                try {
                    app(OrderStateMachine::class)->complete($record, auth()->user());

                    Notification::make()
                        ->title("Order {$record->nomor} selesai")
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Tidak bisa diselesaikan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Print the surat jalan.
     *
     * Opens in a new tab rather than downloading: the warehouse prints it, and
     * a browser print dialogue is one keystroke from there.
     */
    public static function suratJalan(string $name = 'surat_jalan'): Action
    {
        return Action::make($name)
            ->label('Surat jalan')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (Order $record) => route('dokumen.surat-jalan', $record))
            ->openUrlInNewTab()
            // Only once stock is actually committed to this order. Printing a
            // delivery note for an order nobody has approved is how goods leave
            // the building without a sale behind them.
            //
            // And only for the roles the route will actually let through:
            // picking and shipping is the warehouse's job. The controller
            // enforces this regardless, but a button that 403s when clicked is
            // a bug report waiting to be filed.
            ->visible(fn (Order $record) => auth()->user()?->role()->canPickAndShip()
                && ($record->status->holdsReservation()
                    || in_array($record->status, [OrderStatus::Shipped, OrderStatus::Completed], true)));
    }

    /**
     * The faktur, from the order it was issued against.
     *
     * The exact inverse of the surat jalan above, and deliberately so: that
     * document is all goods and no money and belongs to the warehouse, this one
     * is all money and no goods and belongs to everyone else. Sales and finance
     * live on the order screen, and "which invoice was that" should not mean a
     * trip to another resource.
     */
    public static function faktur(string $name = 'faktur'): Action
    {
        return Action::make($name)
            ->label('Faktur')
            // Neither a printer nor a money-document: the surat jalan above is
            // already the printer and "Tagihkan" is already the money-document.
            // Three identical glyphs in one action bar is a coin toss for
            // whoever is standing at the screen.
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->url(fn (Order $record) => route('dokumen.faktur', $record->invoice))
            ->openUrlInNewTab()
            // Nothing to print until the order has been invoiced, which happens
            // when it moves to awaiting_payment.
            ->visible(fn (Order $record) => auth()->user()?->role()->canSeeCreditData()
                && $record->invoice !== null);
    }

    /**
     * Everything a staff member might do to an order, in workflow order.
     *
     * Each one hides itself when it does not apply, so a screen can offer the
     * whole set and the user sees only the one or two that are live.
     *
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::setujui(),
            self::tagihkan(),
            self::kirim(),
            self::selesaikan(),
            self::suratJalan(),
            self::faktur(),
            self::tolak(),
            self::hapus(),
        ];
    }
}
