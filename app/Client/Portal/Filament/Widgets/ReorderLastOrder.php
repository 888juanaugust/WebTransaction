<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Widgets;

use App\Client\Portal\Domain\BuyerOrderPlacer;
use App\Client\Portal\Filament\Resources\Orders\OrderResource;
use App\Client\Portal\Portal;
use App\Models\Sales\SalesOrder;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use RuntimeException;

/** The last order's lines with the quantities editable, and one button to order them again. Buyers restock; they do not shop. */
class ReorderLastOrder extends Widget
{
    protected string $view = 'client.portal.widgets.reorder-last-order';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, string> line id → quantity */
    public array $quantities = [];

    public ?int $orderId = null;

    public function mount(): void
    {
        $order = $this->order();
        $this->orderId = $order?->id;
        foreach ($order?->lines()->get() ?? [] as $line) {
            $this->quantities[$line->id] = (string) rtrim(rtrim((string) $line->quantity, '0'), '.');
        }
    }

    public function order(): ?SalesOrder
    {
        $query = SalesOrder::query()->where('customer_id', Portal::customer()->id)->with(['lines.item', 'lines.unit'])->orderByDesc('trans_date')->orderByDesc('id');

        return $this->orderId ? $query->whereKey($this->orderId)->first() : $query->first();
    }

    public function placeAgain(): void
    {
        $order = $this->order();
        if ($order === null) {
            return;
        }
        try {
            $again = app(BuyerOrderPlacer::class)->repeat(Portal::buyer(), $order, $this->quantities);
            Notification::make()->title(__('Order :number placed', ['number' => $again->number]))->body(__('It is with your marketing for approval.'))->success()->persistent()->send();
            $this->redirect(OrderResource::getUrl('view', ['record' => $again]));
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Cannot place the order'))->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
