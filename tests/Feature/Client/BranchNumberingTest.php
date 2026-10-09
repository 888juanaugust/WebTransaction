<?php

namespace Tests\Feature\Client;

use App\Client\Seeders\BranchSeeder;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\NumberPattern;
use App\Domain\Numbering\ResetRule;
use App\Domain\Numbering\TransactionType;
use App\Models\Company\Branch;
use App\Models\Settings\DocumentSeries;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/** Document numbers carry the branch code, and each branch counts alone within the month. */
class BranchNumberingTest extends TestCase
{
    public function test_the_branch_token_parses_and_renders_the_code(): void
    {
        $pattern = NumberPattern::fromFormat('SO-BR-YYMM-####');

        $this->assertSame(['text', 'branch', 'text', 'short_year', 'month', 'text', 'counter'], array_column($pattern->toArray(), 'token'));
        $this->assertTrue($pattern->hasBranch());
        $this->assertFalse(NumberPattern::fromFormat('SO-YYMM-####')->hasBranch());
        $this->assertSame('SO-JKT-2610-0007', $pattern->render(CarbonImmutable::parse('2026-10-17'), 7, 4, 'JKT'));
        $this->assertSame('BG-2610-0001', NumberPattern::fromFormat('BG-YYMM-####')->render(CarbonImmutable::parse('2026-10-17'), 1, 4), 'a B in separator text is not the branch token');
    }

    public function test_a_branch_format_refuses_a_document_without_a_branch_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumberPattern::fromFormat('SO-BR-YYMM-####')->render(CarbonImmutable::parse('2026-10-17'), 1, 4);
    }

    public function test_each_branch_counts_alone_within_the_period(): void
    {
        $series = DocumentSeries::query()->create([
            'name' => 'Branched', 'transaction_type' => TransactionType::SalesOrder, 'reset_rule' => ResetRule::Monthly, 'counter_digits' => 4,
            'pattern' => NumberPattern::fromFormat('SO-BR-YYMM-####')->toArray(), 'used_all_user' => true, 'is_default' => true, 'is_active' => true,
        ]);
        $gen = app(NumberGenerator::class);
        $oct = CarbonImmutable::parse('2026-10-17');

        $this->assertSame('SO-JKT-2610-0001', $gen->preview($series, $oct, 'JKT'));
        $this->assertSame('SO-JKT-2610-0001', $gen->next($series, $oct, 'JKT'));
        $this->assertSame('SO-SBY-2610-0001', $gen->next($series, $oct, 'SBY'));
        $this->assertSame('SO-JKT-2610-0002', $gen->next($series, $oct, 'JKT'));
        $this->assertSame('SO-JKT-2611-0001', $gen->next($series, $oct->addMonth(), 'JKT'));
        $this->assertSame('SO-SBY-2610-0002', $gen->preview($series, $oct, 'SBY'));

        $this->expectException(InvalidArgumentException::class);
        $gen->next($series, $oct);
    }

    public function test_the_defaults_give_the_head_office_a_code_and_the_branched_series_their_token(): void
    {
        $this->seed();

        $this->assertSame(BranchSeeder::DEFAULT_CODE, Branch::default()->code);
        foreach ([TransactionType::SalesOrder, TransactionType::DeliveryOrder, TransactionType::SalesInvoice, TransactionType::GoodsReceipt] as $type) {
            $series = app(NumberGenerator::class)->defaultSeries($type);
            $this->assertTrue($series->pattern()->hasBranch(), "{$type->value} numbers carry the branch");
        }
        $this->assertFalse(app(NumberGenerator::class)->defaultSeries(TransactionType::JournalVoucher)->pattern()->hasBranch());
        $this->assertSame('SO-PST-2610-0001', app(NumberGenerator::class)->preview(app(NumberGenerator::class)->defaultSeries(TransactionType::SalesOrder), CarbonImmutable::parse('2026-10-17'), Branch::default()->code));
    }
}
