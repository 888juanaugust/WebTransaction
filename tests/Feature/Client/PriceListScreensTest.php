<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\PriceList\PriceListImporter;
use App\Client\Filament\Resources\CustomerPriceRules\Pages\ManageCustomerPriceRules;
use App\Client\Filament\Resources\PriceListImports\Pages\CreatePriceListImport;
use App\Client\Filament\Resources\PriceListImports\Pages\ListPriceListImports;
use App\Client\Filament\Resources\PriceListImports\Pages\ViewPriceListImport;
use App\Client\Jobs\ParsePriceListImport;
use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Client\Support\Workbooks;
use Tests\TestCase;

/** The Price List and Customer Prices screens: who opens them, the upload, the review page and its decisions. */
class PriceListScreensTest extends TestCase
{
    use Workbooks;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = $this->actingAsAdmin();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkbooks();
        parent::tearDown();
    }

    private function parsedImport(array $rows): PriceListImport
    {
        $path = $this->supplierWorkbook($rows);
        $stored = 'price-lists/'.basename($path);
        Storage::disk('local')->put($stored, (string) file_get_contents($path));
        $import = PriceListImport::query()->create(['original_filename' => basename($path), 'stored_path' => $stored, 'format' => PriceListImport::SUPPLIER, 'status' => PriceListImport::UPLOADED, 'uploaded_by' => $this->owner->id]);
        (new ParsePriceListImport($import->id))->handle(app(PriceListImporter::class));

        return $import->fresh();
    }

    public function test_inventory_works_the_screens_and_sales_only_reads_them(): void
    {
        $inventory = User::factory()->create(['is_active' => true]);
        CentralGroups::find(CentralGroups::PURCHASING)->users()->attach($inventory);
        $sales = User::factory()->create(['is_active' => true]);
        CentralGroups::find(CentralGroups::SALES)->users()->attach($sales);

        $this->actingAs($inventory);
        $this->get('/admin/client/price-list')->assertOk();
        $this->get('/admin/client/price-list/create')->assertOk();
        $this->get('/admin/client/customer-prices')->assertOk();

        $this->actingAs($sales);
        $this->get('/admin/client/price-list')->assertOk();
        $this->get('/admin/client/price-list/create')->assertForbidden();
        $this->get('/admin/client/customer-prices')->assertOk();
    }

    public function test_an_upload_is_stored_with_its_checksum_and_processed(): void
    {
        $path = $this->supplierWorkbook([$this->supplierRow('A1', 100_000)]);

        Livewire::test(CreatePriceListImport::class)
            ->fillForm([
                'stored_path' => UploadedFile::fake()->createWithContent('harga.xlsx', (string) file_get_contents($path)),
                'format' => PriceListImport::SUPPLIER,
                'effective_from' => '2026-10-20',
                'is_full_replacement' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $import = PriceListImport::query()->sole();
        $this->assertSame('harga.xlsx', $import->original_filename);
        $this->assertSame(hash_file('sha256', $path), $import->checksum);
        $this->assertSame(PriceListImport::PARSED, $import->status, 'the queue is sync in tests');
        $this->assertSame(1, $import->row_count);
        Storage::disk('local')->assertExists($import->stored_path);
    }

    public function test_the_review_page_shows_the_diff_and_publishes_or_discards(): void
    {
        $import = $this->parsedImport([$this->supplierRow('A1', 100_000), $this->supplierRow('BAD', 'x')]);

        $page = Livewire::test(ViewPriceListImport::class, ['record' => $import->getRouteKey()])
            ->assertOk()
            ->assertSee('Ready to review')
            ->assertSee('New SKUs')
            ->assertSee('BAD')
            ->assertActionVisible('publish')
            ->assertActionVisible('discard');

        $page->callAction('publish', ['effective_from' => today()->toDateString()])->assertNotified();
        $this->assertSame(PriceListImport::PUBLISHED, $import->fresh()->status);
        $this->assertNotNull(PriceListVersion::current());
        $this->assertSame(today()->toDateString(), PriceListVersion::current()->effective_from->toDateString());

        $second = $this->parsedImport([$this->supplierRow('A1', 100_000)]);
        Livewire::test(ViewPriceListImport::class, ['record' => $second->getRouteKey()])->callAction('discard')->assertNotified();
        $this->assertSame(PriceListImport::DISCARDED, $second->fresh()->status);

        Livewire::test(ListPriceListImports::class)->assertOk()->assertSee('Published')->assertActionVisible('export');
    }

    public function test_a_tripped_brake_asks_for_the_second_confirmation(): void
    {
        $this->parsedImport([$this->supplierRow('A1', 100_000)]);
        PriceListImport::query()->first()->forceFill(['status' => PriceListImport::PARSED])->save();
        Livewire::test(ViewPriceListImport::class, ['record' => PriceListImport::query()->first()->getRouteKey()])->callAction('publish', ['effective_from' => today()->subDay()->toDateString()]);
        $import = $this->parsedImport([$this->supplierRow('A1', 300_000)]);
        $this->assertTrue($import->brakeTripped());

        Livewire::test(ViewPriceListImport::class, ['record' => $import->getRouteKey()])
            ->callAction('publish', ['effective_from' => today()->toDateString()])
            ->assertHasActionErrors(['acknowledgement']);
        $this->assertSame(PriceListImport::PARSED, $import->fresh()->status);

        Livewire::test(ViewPriceListImport::class, ['record' => $import->getRouteKey()])
            ->callAction('publish', ['effective_from' => today()->toDateString(), 'acknowledgement' => 'confirmed with the supplier'])
            ->assertHasNoActionErrors();
        $this->assertSame(PriceListImport::PUBLISHED, $import->fresh()->status);
    }

    public function test_a_customer_price_rule_is_made_from_the_screen(): void
    {
        $customer = $this->sampleCustomer();
        $item = $this->sampleItem();

        Livewire::test(ManageCustomerPriceRules::class)
            ->callAction('create', ['customer_id' => $customer->id, 'item_id' => $item->id, 'min_base_quantity' => 10, 'price' => 95000, 'reason' => 'tender'])
            ->assertHasNoActionErrors();

        $rule = CustomerPriceRule::query()->sole();
        $this->assertSame(95_000, $rule->price);
        $this->assertSame($this->owner->id, $rule->created_by);
    }
}
