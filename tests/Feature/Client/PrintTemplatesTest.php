<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Client\Domain\Warehouse\DeliveryMaker;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Numbering\TransactionType;
use App\Domain\Printing\PdfRenderer;
use App\Domain\Printing\Printable;
use App\Domain\Printing\PrintJob;
use App\Domain\Shared\Format;
use App\Models\Company\PrintLayout;
use App\Models\Company\Shipment;
use App\Models\Sales\InvoiceExchange;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Central's prints: the surat jalan (no price), the surat pengantar slip and the tanda terima faktur, each a layout on its own template. */
class PrintTemplatesTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
    }

    private function render(string $alias, int $id): string
    {
        $print = app(PrintJob::class)->data($alias, $id, $this->owner);
        $template = (string) ($print['layout']['template'] ?? 'print.document');

        return view($template, $print)->render();
    }

    public function test_the_layouts_are_seeded_with_the_surat_jalan_as_the_deliverys_default(): void
    {
        $delivery = PrintLayout::query()->where('transaction_type', TransactionType::DeliveryOrder->value)->get();
        $this->assertEqualsCanonicalizing(['Standard', 'Surat Jalan', 'Surat Pengantar'], $delivery->pluck('name')->all());
        $this->assertSame('Surat Jalan', $delivery->firstWhere('is_default', true)->name);
        $this->assertSame('client.print.surat-jalan', $delivery->firstWhere('name', 'Surat Jalan')->settings()['template']);
        $this->assertSame('Tanda Terima Faktur', PrintLayout::query()->where('transaction_type', TransactionType::InvoiceExchange->value)->where('is_default', true)->sole()->name);
        $this->assertNotNull(Printable::for('invoice_exchange'));
    }

    public function test_the_surat_jalan_prints_the_lines_without_a_price_and_the_expedition_in_the_note(): void
    {
        $expedition = Shipment::query()->create(['name' => 'Surya Bintang', 'pic_name' => 'Pak Budi', 'pic_phone_number' => '0812', 'is_active' => true]);
        $order = $this->order(3);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->actingAs($this->owner);
        $delivery = app(DeliveryMaker::class)->make($order, $this->gudangJakarta, [], $this->owner, today()->toDateString(), [
            'shipment_id' => $expedition->id, 'vehicle' => 'Pick-up', 'plate_number' => 'L 1234 AB', 'packages' => ['koli' => 2, 'kresek' => 0], 'shipping_note' => 'Siang',
        ]);

        $this->assertStringStartsWith('SJ-', $delivery->number);
        $this->assertSame($expedition->id, $delivery->shipment_id);
        $this->assertSame(['koli' => 2], json_decode((string) $delivery->packages, true));

        $html = $this->render('delivery', $delivery->id);
        $this->assertStringContainsString('Surat Jalan', $html);
        $this->assertStringContainsString('Surya Bintang · Siang', $html);
        $this->assertStringContainsString($this->item->number, $html);
        $this->assertStringContainsString('Collected by', $html);
        $this->assertStringContainsString('Warehouse head signature', $html);
        $this->assertStringNotContainsString('Rp', $html, 'a surat jalan carries no price');
        $this->assertStringNotContainsString('150.000', $html);
        $this->assertSame(3, substr_count($html, 'class="sheet copy"'), 'three copies');

        $pdf = app(PdfRenderer::class)->render('delivery', $delivery->id, $this->owner);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_the_surat_pengantar_prints_the_package_counts_and_the_vehicle(): void
    {
        $order = $this->order(2);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->actingAs($this->owner);
        $delivery = app(DeliveryMaker::class)->make($order, $this->gudangJakarta, [], $this->owner, null, [
            'vehicle' => 'Truk', 'plate_number' => 'B 9 X', 'packages' => ['koli' => 1, 'palet' => 3], 'goods_description' => 'SPARE PART',
        ]);
        $layout = PrintLayout::query()->where('name', 'Surat Pengantar')->sole();

        $print = app(PrintJob::class)->data('delivery', $delivery->id, $this->owner, $layout->id);
        $html = view($print['layout']['template'], $print)->render();

        $this->assertStringContainsString('Surat Pengantar', $html);
        $this->assertStringContainsString('Truk', $html);
        $this->assertStringContainsString('B 9 X', $html);
        $this->assertStringContainsString('SPARE PART', $html);
        $this->assertStringContainsString('Receiver', $html);
        $this->assertMatchesRegularExpression('/class="cell">1<\/span><span>Boxes/', $html);
        $this->assertMatchesRegularExpression('/class="cell">3<\/span><span>Pallets/', $html);
        $this->assertMatchesRegularExpression('/class="cell"><\/span><span>Bags/', $html, 'an empty kind prints an empty box');
    }

    public function test_the_tanda_terima_faktur_lists_the_invoices_handed_over(): void
    {
        $this->actingAs($this->owner);
        $invoice = $this->invoice(1, 100_000);
        $exchange = InvoiceExchange::query()->create(['number' => 'TTF-1', 'trans_date' => today()->toDateString(), 'customer_id' => $this->customer->id, 'collect_date' => today()->addDays(7)->toDateString(), 'due_date' => today()->addDays(30)->toDateString(), 'created_by' => $this->owner->id]);
        $exchange->lines()->create(['sort' => 0, 'sales_invoice_id' => $invoice->id]);
        $exchange->refreshTotal();
        $this->docs->created($exchange);

        $html = $this->render('invoice_exchange', $exchange->id);

        $this->assertStringContainsString('Tanda Terima Faktur', $html);
        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('Received by', $html);
        $this->assertStringContainsString(Format::rupiah((int) $invoice->total), $html);
    }
}
