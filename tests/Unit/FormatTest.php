<?php

namespace Tests\Unit;

use App\Domain\Shared\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_dates_read_as_design_md_asks(): void
    {
        $this->assertSame('17 Oct 2026', Format::date('2026-10-17'));
        $this->assertSame('17/10/2026', Format::dateInput('2026-10-17'));
        $this->assertSame('', Format::date(null));
    }

    public function test_quantities_drop_trailing_zeros_and_use_a_decimal_comma(): void
    {
        $this->assertSame('12', Format::quantity('12.0000'));
        $this->assertSame('2,5', Format::quantity(2.5));
        $this->assertSame('1.250,25', Format::quantity('1250.25'));
        $this->assertSame('12%', Format::percent(12));
        $this->assertSame('2,5%', Format::percent('2.50'));
    }
}
