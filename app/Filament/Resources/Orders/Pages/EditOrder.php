<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ajukan')
                ->label('Ajukan untuk persetujuan')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Order akan masuk antrean persetujuan. Setelah diajukan, baris tidak bisa diubah.')
                ->visible(fn () => $this->record->status === OrderStatus::Draft)
                ->action(function () {
                    // Save any unsaved edits first, or the submitted order is
                    // not the one on screen.
                    $this->save(shouldRedirect: false);

                    try {
                        /*
                         * Submit — and when the submitter holds the approval
                         * seat (the customer's own marketing, or the owner),
                         * approve in the same breath. Asking marketing to
                         * click approve on their own submission a second
                         * later would be ceremony, not control.
                         */
                        $order = app(OrderStateMachine::class)
                            ->submitAndMaybeApprove($this->record->refresh(), auth()->user());

                        Notification::make()
                            ->title($order->status === OrderStatus::Confirmed
                                ? "Order {$order->nomor} diajukan dan langsung disetujui"
                                : "Order {$order->nomor} diajukan")
                            ->body($order->status === OrderStatus::Confirmed
                                ? 'Harga terkunci dan stok dipesan.'
                                : 'Menunggu persetujuan marketing penanggung jawab.')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title('Tidak bisa diajukan')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
