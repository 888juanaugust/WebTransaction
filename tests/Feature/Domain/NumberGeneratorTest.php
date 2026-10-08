<?php

namespace Tests\Feature\Domain;

use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Models\Settings\DocumentSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\System\DocumentSeriesSeeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class NumberGeneratorTest extends TestCase
{
    public function test_the_short_notation_parses_into_tokens_and_renders(): void
    {
        $pattern = NumberPattern::fromFormat('SO-YYMM-####');

        $this->assertSame(['text', 'short_year', 'month', 'text', 'counter'], array_column($pattern->toArray(), 'token'));
        $this->assertSame('SO-2610-0412', $pattern->render(CarbonImmutable::parse('2026-10-17'), 412, 4));

        $this->assertSame('INV/2026/X/17/00007', NumberPattern::fromFormat('INV/YYYY/RM/DD/#####')->render(CarbonImmutable::parse('2026-10-17'), 7, 5));
        $this->assertSame('C-00001', NumberPattern::fromFormat('C-#####')->render(CarbonImmutable::parse('2026-01-01'), 1, 5));
    }

    public function test_a_pattern_needs_exactly_one_counter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumberPattern::fromFormat('SO-YYMM');
    }

    public function test_numbers_run_in_sequence_and_reset_per_period(): void
    {
        $series = $this->series('SO-YYMM-####', ResetRule::Monthly);
        $gen = app(NumberGenerator::class);
        $oct = CarbonImmutable::parse('2026-10-17');

        $this->assertSame('SO-2610-0001', $gen->preview($series, $oct));
        $this->assertSame('SO-2610-0001', $gen->next($series, $oct));
        $this->assertSame('SO-2610-0002', $gen->next($series, $oct));
        $this->assertSame('SO-2610-0003', $gen->preview($series, $oct));
        $this->assertSame('SO-2611-0001', $gen->next($series, $oct->addMonth()));
        $this->assertSame('SO-2610-0003', $gen->next($series, $oct), 'a back-dated document continues its own month');
    }

    public function test_yearly_daily_and_never_reset_rules(): void
    {
        $gen = app(NumberGenerator::class);
        $d1 = CarbonImmutable::parse('2026-10-17');
        $d2 = CarbonImmutable::parse('2026-12-01');
        $d3 = CarbonImmutable::parse('2027-01-02');

        $yearly = $this->series('JV-YYYY-####', ResetRule::Yearly, 'y');
        $gen->next($yearly, $d1);
        $this->assertSame('JV-2026-0002', $gen->next($yearly, $d2));
        $this->assertSame('JV-2027-0001', $gen->next($yearly, $d3));

        $daily = $this->series('CB-YYMMDD-###', ResetRule::Daily, 'd', 3);
        $this->assertSame('CB-261017-001', $gen->next($daily, $d1));
        $this->assertSame('CB-261201-001', $gen->next($daily, $d2));

        $never = $this->series('C-#####', ResetRule::None, 'n', 5);
        $gen->next($never, $d1);
        $this->assertSame('C-00002', $gen->next($never, $d3));
    }

    public function test_the_counter_advances_in_one_atomic_statement(): void
    {
        $series = $this->series('SO-YYMM-####', ResetRule::Monthly);
        $gen = app(NumberGenerator::class);
        $date = CarbonImmutable::parse('2026-10-17');

        $drawn = [];
        for ($i = 0; $i < 200; $i++) {
            $drawn[] = $gen->next($series, $date);
        }

        $this->assertCount(200, array_unique($drawn));
        $this->assertSame(200, (int) DB::table('document_counters')->where('document_series_id', $series->id)->value('last_value'));
    }

    public function test_the_seeded_defaults_cover_every_transaction_type_and_follow_the_design_formats(): void
    {
        $this->seed(DocumentSeriesSeeder::class);
        $gen = app(NumberGenerator::class);
        $date = CarbonImmutable::parse('2026-10-17');

        foreach (TransactionType::cases() as $type) {
            $this->assertNotNull($gen->defaultSeries($type), $type->value);
        }
        $this->assertSame('SO-2610-0001', $gen->next($gen->defaultSeries(TransactionType::SalesOrder), $date));
        $this->assertSame('PO-2610-0001', $gen->next($gen->defaultSeries(TransactionType::PurchaseOrder), $date));
        $this->assertSame('INV-2610-0001', $gen->next($gen->defaultSeries(TransactionType::SalesInvoice), $date));
        $this->assertSame('DO-2610-0001', $gen->next($gen->defaultSeries(TransactionType::DeliveryOrder), $date));
        $this->assertSame('GR-2610-0001', $gen->next($gen->defaultSeries(TransactionType::GoodsReceipt), $date));
        $this->assertSame('C-00001', $gen->next($gen->defaultSeries(TransactionType::Customer), $date));
    }

    public function test_a_series_limited_to_some_users_is_offered_only_to_them(): void
    {
        $series = $this->series('SO-YYMM-####', ResetRule::Monthly);
        $private = $this->series('SOX-YYMM-####', ResetRule::Monthly, 'private');
        $private->update(['used_all_user' => false]);
        $insider = User::factory()->create();
        $outsider = User::factory()->create();
        $private->users()->attach($insider);

        $gen = app(NumberGenerator::class);
        $this->assertEqualsCanonicalizing([$series->id, $private->id], $gen->seriesFor(TransactionType::SalesOrder, $insider)->pluck('id')->all());
        $this->assertSame([$series->id], $gen->seriesFor(TransactionType::SalesOrder, $outsider)->pluck('id')->all());
    }

    private function series(string $format, ResetRule $reset, string $name = 'Default', int $digits = 4): DocumentSeries
    {
        return DocumentSeries::query()->create([
            'name' => $name,
            'transaction_type' => TransactionType::SalesOrder,
            'reset_rule' => $reset,
            'counter_digits' => $digits,
            'pattern' => NumberPattern::fromFormat($format)->toArray(),
            'used_all_user' => true,
            'is_default' => $name === 'Default',
        ]);
    }
}
