<?php

namespace Tests\Feature\Domain;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\FixedAssets\DepreciationRun;
use App\Domain\FixedAssets\Depreciator;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetChange;
use App\Models\FixedAssets\AssetDisposal;
use App\Models\FixedAssets\AssetLocation;
use App\Models\FixedAssets\AssetTransfer;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    private DocumentRepository $docs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-15 09:00:00');
        CarbonImmutable::setTestNow('2026-12-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->docs = app(DocumentRepository::class);
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no, ?string $asOf = null): int
    {
        return AccountBalances::asOf($asOf)[$this->account($no)] ?? 0;
    }

    private function asset(string $number, int $cost, int $salvage, int $life, DepreciationMethod $method, string $usage = '2026-01-15'): FixedAsset
    {
        $category = AssetCategory::query()->where('name', 'Equipment')->firstOrFail();
        $asset = FixedAsset::query()->create([
            'number' => $number, 'name' => "Asset {$number}", 'trans_date' => '2026-01-10', 'usage_date' => $usage,
            'depreciation_method' => $method, 'asset_account_id' => $category->asset_account_id,
            'accumulated_depreciation_account_id' => $category->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => $category->depreciation_expense_account_id,
            'quantity' => 1, 'useful_life_months' => $life, 'salvage_value' => $salvage, 'asset_category_id' => $category->id,
            'location_id' => AssetLocation::query()->value('id'), 'created_by' => auth()->id(),
        ]);
        $asset->expenditures()->create(['sort' => 0, 'account_id' => $this->account('1102'), 'description' => 'Paid by transfer', 'trans_date' => '2026-01-10', 'amount' => $cost]);
        $asset->refreshTotal();
        $this->docs->created($asset);

        return $asset->fresh();
    }

    public function test_the_calculator_lands_every_method_on_the_salvage_value(): void
    {
        $this->assertSame(1_285_714, Depreciator::monthly(DepreciationMethod::StraightLine, 9_000_000, 10_000_000, 7, 7));
        $this->assertSame(1_285_716, Depreciator::monthly(DepreciationMethod::StraightLine, 1_285_716, 2_285_716, 1, 7), 'the last month takes the remainder');
        $this->assertSame(1_000_000, Depreciator::monthly(DepreciationMethod::DecliningBalance, 12_000_000, 12_000_000, 24, 24));
        $this->assertSame(916_667, Depreciator::monthly(DepreciationMethod::DecliningBalance, 11_000_000, 11_000_000, 23, 24));
        $this->assertSame(3_000_000, Depreciator::monthly(DepreciationMethod::SumOfYears, 6_000_000, 6_000_000, 3, 3));
        $this->assertSame(2_000_000, Depreciator::monthly(DepreciationMethod::SumOfYears, 3_000_000, 3_000_000, 2, 3));
        $this->assertSame(0, Depreciator::monthly(DepreciationMethod::None, 6_000_000, 6_000_000, 3, 3));
        $this->assertSame(0, Depreciator::monthly(DepreciationMethod::StraightLine, 0, 1_000_000, 3, 12), 'nothing left above salvage');
    }

    public function test_acquisition_posts_and_the_run_depreciates_month_by_month_without_repeating(): void
    {
        $asset = $this->asset('FA-00001', 12_000_000, 0, 12, DepreciationMethod::StraightLine);
        $this->assertSame(12_000_000, $asset->cost);
        $this->assertSame(12_000_000, $this->balance('1500'));
        $this->assertSame(-12_000_000, $this->balance('1102'));

        $run = app(DepreciationRun::class);
        $result = $run->upTo('2026-03-31');
        $this->assertSame(['posted' => 3, 'amount' => 3_000_000, 'skipped' => []], $result);
        $this->assertSame(3_000_000, $asset->accumulatedDepreciation());
        $this->assertSame(9_000_000, $asset->bookValue());
        $this->assertSame(3_000_000, $this->balance('6400'));
        $this->assertSame(3_000_000, $this->balance('1510'), 'accumulated depreciation, read on its credit side');
        $this->assertSame(['202601', '202602', '202603'], $asset->depreciations()->pluck('period')->all());
        $this->assertSame('2026-02-28', $asset->depreciations()->where('period', '202602')->first()->trans_date->toDateString(), 'posted on the month\'s last day');
        $this->assertSame(1_000_000, $this->balance('6400', '2026-01-31'));

        $this->assertSame(0, $run->upTo('2026-03-31')['posted'], 'idempotent');
        $this->assertSame(9, $run->upTo('2026-12-31')['posted']);
        $this->assertSame(12_000_000, $asset->accumulatedDepreciation());
        $this->assertSame(0, $asset->bookValue());
        $this->assertSame(0, $run->upTo('2027-06-30')['posted'], 'fully depreciated');
    }

    public function test_declining_balance_and_sum_of_years_reach_the_salvage_value_in_the_last_month(): void
    {
        $declining = $this->asset('FA-00002', 12_000_000, 2_000_000, 24, DepreciationMethod::DecliningBalance);
        $digits = $this->asset('FA-00003', 6_000_000, 0, 3, DepreciationMethod::SumOfYears);

        $run = app(DepreciationRun::class);
        $run->upTo('2027-12-31');

        $this->assertLessThanOrEqual(24, $declining->depreciations()->count(), 'the book value meets the salvage floor before the life ends, then stops');
        $this->assertSame(1_000_000, $declining->depreciations()->first()->amount);
        $this->assertSame(916_667, $declining->depreciations()->where('period', '202602')->first()->amount);
        $this->assertSame(10_000_000, $declining->accumulatedDepreciation());
        $this->assertSame(2_000_000, $declining->bookValue(), 'down to salvage, never below');

        $this->assertSame([3_000_000, 2_000_000, 1_000_000], $digits->depreciations()->pluck('amount')->all());
        $this->assertSame(0, $digits->bookValue());
    }

    public function test_a_change_adds_cost_and_new_terms_from_the_next_month_and_a_disposal_books_the_loss(): void
    {
        $asset = $this->asset('FA-00004', 12_000_000, 0, 12, DepreciationMethod::StraightLine);
        $run = app(DepreciationRun::class);
        $run->upTo('2026-03-31');

        $change = AssetChange::query()->create(['number' => 'FAC-1', 'fixed_asset_id' => $asset->id, 'change_type' => AssetChange::DATA, 'trans_date' => '2026-04-05', 'new_useful_life_months' => 12, 'description' => 'Upgrade kit', 'created_by' => auth()->id()]);
        $change->expenditures()->create(['sort' => 0, 'account_id' => $this->account('1102'), 'description' => 'Upgrade kit', 'amount' => 2_400_000]);
        $change->refreshTotal();
        $this->docs->created($change);

        $this->assertSame(14_400_000, $asset->fresh()->cost);
        $this->assertSame(14_400_000, $this->balance('1500'));
        $this->assertSame(1, $run->upTo('2026-04-30')['posted']);
        $this->assertSame(1_266_667, $asset->depreciations()->where('period', '202604')->first()->amount, '(14.400.000 − 3.000.000) over the 9 months left');

        $disposal = AssetDisposal::query()->create(['number' => 'FAD-1', 'fixed_asset_id' => $asset->id, 'trans_date' => '2026-05-15', 'quantity' => 1, 'gain_loss_account_id' => $this->account('7100'), 'selling_asset' => true, 'proceeds' => 10_000_000, 'proceeds_account_id' => $this->account('1102'), 'description' => 'Sold', 'created_by' => auth()->id()]);
        $disposal->refreshTotal();
        $this->docs->created($disposal);

        $disposal->refresh();
        $this->assertSame(14_400_000, $disposal->cost_removed);
        $this->assertSame(4_266_667, $disposal->depreciation_removed);
        $this->assertSame(10_000_000 - (14_400_000 - 4_266_667), $disposal->gain_loss, 'a loss of 133.333');
        $this->assertSame(0, $this->balance('1500'));
        $this->assertSame(0, $this->balance('1510'));
        $this->assertSame(-133_333, $this->balance('7100'), 'the loss debits the gain/loss account');
        $this->assertSame(-12_000_000 - 2_400_000 + 10_000_000, $this->balance('1102'));
        $this->assertTrue($asset->fresh()->isDisposed());
        $this->assertSame('2026-05-15', $asset->fresh()->disposed_on->toDateString());
        $this->assertSame(0, $run->upTo('2026-06-30')['posted'], 'a disposed asset no longer depreciates');

        $this->docs->delete($disposal->fresh());
        $this->assertFalse($asset->fresh()->isDisposed());
        $this->assertSame(0, Posting::active()->where('posting_key', $disposal->postingKey())->count());
        $this->assertSame(14_400_000, $this->balance('1500'));
    }

    public function test_a_transfer_moves_the_assets_between_locations(): void
    {
        $asset = $this->asset('FA-00005', 1_000_000, 0, 12, DepreciationMethod::None);
        $from = AssetLocation::query()->firstOrFail();
        $to = AssetLocation::query()->create(['name' => 'Warehouse B', 'address' => 'Jl. Gudang 2', 'is_active' => true]);

        $transfer = AssetTransfer::query()->create(['number' => 'FAT-1', 'trans_date' => '2026-02-01', 'from_location_id' => $from->id, 'to_location_id' => $to->id, 'created_by' => auth()->id()]);
        $transfer->lines()->create(['sort' => 0, 'fixed_asset_id' => $asset->id, 'quantity' => 1, 'memo' => 'Moved']);
        $this->docs->created($transfer);
        $this->assertSame($to->id, $asset->fresh()->location_id);

        $this->docs->delete($transfer->fresh());
        $this->assertSame($from->id, $asset->fresh()->location_id);
        $this->assertSame(0, app(DepreciationRun::class)->upTo('2026-12-31')['posted'], 'not depreciated');
    }
}
