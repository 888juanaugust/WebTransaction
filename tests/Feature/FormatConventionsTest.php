<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;
use App\Domain\Shared\Money;
use App\Filament\Support\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Tests\TestCase;

/** Numbers and dates follow the Other tab of Preferences; the defaults read exactly as before. */
class FormatConventionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    public function test_the_defaults_are_the_indonesian_convention_unchanged(): void
    {
        $this->assertSame('1.500.000', Money::format(1_500_000));
        $this->assertSame('Rp 1.500.000', Format::money(1_500_000));
        $this->assertSame('-1.500.000', Format::number(-1_500_000));
        $this->assertSame(1_500_001, Money::parse('1.500.000,60'));
        $this->assertSame('1.250,25', Format::quantity('1250.25'));
        $this->assertSame('2,3456', Format::quantity('2.3456'), 'up to four decimals');
        $this->assertSame('150.000', Format::price('150000.0000'));
        $this->assertSame('17/10/2026', Format::dateInput('2026-10-17'));
        $this->assertSame('17 Oct 2026', Format::date('2026-10-17'));
        $this->assertSame('d/m/Y', DatePicker::make('d')->getDisplayFormat());
        $this->assertStringContainsString("\$money(\$input, ',', '.', 0)", (string) MoneyInput::make('amount')->getMask()?->toHtml());
    }

    public function test_the_english_convention_and_the_chosen_decimals_and_date_format(): void
    {
        $prefs = app(Preferensi::class);
        $prefs->setMany([
            PreferensiKey::DecimalFormat->value => 'en',
            PreferensiKey::QuantityDecimals->value => '2',
            PreferensiKey::PriceDecimals->value => '2',
            PreferensiKey::DateFormat->value => 'Y-m-d',
        ]);

        $this->assertSame('1,500,000', Money::format(1_500_000));
        $this->assertSame('Rp 1,500,000', Format::money(1_500_000));
        $this->assertSame(1_500_001, Money::parse('1,500,000.60'));
        $this->assertSame(1_500_000, Money::parse('1500000'));
        $this->assertSame('1,250.25', Format::quantity('1250.25'));
        $this->assertSame('2.35', Format::quantity('2.3456'), 'two decimals');
        $this->assertSame('12', Format::quantity('12.0000'));
        $this->assertSame('150,000.00', Format::price('150000'));
        $this->assertSame('2026-10-17', Format::dateInput('2026-10-17'));
        $this->assertSame('17 Oct 2026', Format::date('2026-10-17'), 'tables keep the readable date');
        $this->assertSame('Y-m-d', DatePicker::make('d')->getDisplayFormat());
        $this->assertStringContainsString("\$money(\$input, '.', ',', 0)", (string) MoneyInput::make('amount')->getMask()?->toHtml());

        $prefs->set(PreferensiKey::DecimalFormat, 'id');
        $this->assertSame('1.500.000', Money::format(1_500_000), 'a change takes effect at once');
    }
}
