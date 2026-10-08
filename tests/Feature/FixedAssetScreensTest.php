<?php

namespace Tests\Feature;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Posting\AccountBalances;
use App\Filament\Pages\FixedAssets\AssetsByLocation;
use App\Filament\Resources\FixedAssets\AssetDisposals\Pages\CreateAssetDisposal;
use App\Filament\Resources\FixedAssets\AssetTransfers\Pages\CreateAssetTransfer;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\FixedAssets\FixedAssets\Pages\ListFixedAssets;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetDisposal;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FixedAssetScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-15 09:00:00');
        CarbonImmutable::setTestNow('2026-12-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[$this->account($no)] ?? 0;
    }

    public function test_an_asset_is_bought_depreciated_moved_and_disposed_of_through_the_screens(): void
    {
        $category = AssetCategory::query()->where('name', 'Equipment')->firstOrFail();
        $office = AssetLocation::query()->firstOrFail();

        Livewire::test(CreateFixedAsset::class)
            ->fillForm([
                'name' => 'Hydraulic press',
                'trans_date' => '2026-09-10',
                'usage_date' => '2026-10-01',
                'asset_category_id' => $category->id,
                'depreciation_method' => DepreciationMethod::StraightLine->value,
                'quantity' => 1,
                'useful_life_months' => 12,
                'salvage_value' => 0,
                'asset_account_id' => $this->account('1500'),
                'accumulated_depreciation_account_id' => $this->account('1510'),
                'depreciation_expense_account_id' => $this->account('6400'),
                'location_id' => $office->id,
                'expenditures' => [['account_id' => $this->account('1102'), 'description' => 'Paid by transfer', 'trans_date' => '2026-09-10', 'amount' => 12_000_000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = FixedAsset::query()->firstOrFail();
        $this->assertSame('FA-00001', $asset->number);
        $this->assertSame(12_000_000, $asset->cost);
        $this->assertSame(12_000_000, $this->balance('1500'));

        Livewire::test(ListFixedAssets::class)
            ->callAction('runDepreciation', ['until' => '2026-11-30'])
            ->assertHasNoActionErrors()
            ->assertNotified('2 month(s) posted');
        $this->assertSame(2_000_000, $asset->accumulatedDepreciation());
        $this->assertSame(10_000_000, $asset->bookValue());

        Livewire::test(AssetsByLocation::class)
            ->assertSee('Head office')
            ->assertSee('FA-00001');

        $warehouse = AssetLocation::query()->create(['name' => 'Warehouse B', 'address' => 'Jl. Gudang 2', 'is_active' => true]);
        Livewire::test(CreateAssetTransfer::class)
            ->fillForm([
                'trans_date' => '2026-12-01',
                'from_location_id' => $office->id,
                'to_location_id' => $warehouse->id,
                'lines' => [['fixed_asset_id' => $asset->id, 'quantity' => 1, 'memo' => 'Moved to the warehouse']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame($warehouse->id, $asset->fresh()->location_id);

        Livewire::test(CreateAssetDisposal::class)
            ->fillForm([
                'fixed_asset_id' => $asset->id,
                'trans_date' => '2026-12-10',
                'quantity' => 1,
                'gain_loss_account_id' => $this->account('7100'),
                'selling_asset' => true,
                'proceeds' => 11_000_000,
                'proceeds_account_id' => $this->account('1102'),
                'description' => 'Sold on',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $disposal = AssetDisposal::query()->firstOrFail();
        $this->assertSame('FAD-2612-0001', $disposal->number);
        $this->assertSame(12_000_000, $disposal->cost_removed);
        $this->assertSame(2_000_000, $disposal->depreciation_removed);
        $this->assertSame(1_000_000, $disposal->gain_loss, 'sold for more than its book value');
        $this->assertSame(1_000_000, $this->balance('7100'));
        $this->assertSame(0, $this->balance('1500'));
        $this->assertTrue($asset->fresh()->isDisposed());
    }
}
