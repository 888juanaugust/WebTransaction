<?php

namespace Tests\Feature\Domain;

use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Pengaturan\PreferensiTab;
use App\Domain\Pengaturan\PreferensiType;
use App\Models\Company\AuditLog;
use Tests\TestCase;

class PreferensiTest extends TestCase
{
    public function test_every_key_has_a_tab_a_type_a_label_and_a_typed_default(): void
    {
        foreach (PreferensiKey::cases() as $key) {
            $this->assertInstanceOf(PreferensiTab::class, $key->tab());
            $this->assertNotSame('', $key->label());
            $default = $key->default();
            if ($default !== null) {
                $this->assertSame($default, $key->type()->cast($default), $key->value);
            }
            if ($key->type() === PreferensiType::Select) {
                $this->assertArrayHasKey((string) $default, $key->options(), $key->value);
            }
        }
        $this->assertCount(count(PreferensiKey::cases()), array_merge(...array_map(fn (PreferensiTab $t) => $t->keys(), PreferensiTab::cases())));
    }

    public function test_the_defaults_are_what_a_trading_company_starts_with(): void
    {
        $prefs = app(Preferensi::class);

        $this->assertTrue($prefs->get(PreferensiKey::MultiBranch));
        $this->assertFalse($prefs->get(PreferensiKey::Department));
        $this->assertSame(90, $prefs->get(PreferensiKey::AgingRangeDays));
        $this->assertSame(30, $prefs->get(PreferensiKey::AgingIntervalDays));
        $this->assertSame('invoice_date', $prefs->get(PreferensiKey::AgingBasis));
        $this->assertTrue($prefs->get(PreferensiKey::NewCustomerInclusiveTax));
        $this->assertTrue(BusinessRule::SegregationOfDuties->isOn());
        $this->assertFalse(BusinessRule::AllowNegativeStock->isOn());
        $this->assertFalse(BusinessRule::SalesOrderApproval->isOn(), 'orders are approved on entry until a company switches the rule on');
        $this->assertSame(0, $prefs->get(PreferensiKey::CreditNoticeDays));
        $this->assertSame(0, $prefs->get(PreferensiKey::CreditFreezeDays));
    }

    public function test_a_change_is_stored_typed_cached_and_audited(): void
    {
        $admin = $this->actingAsAdmin();
        $prefs = app(Preferensi::class);

        $prefs->set(PreferensiKey::AgingRangeDays, '120', $admin);
        $prefs->set(PreferensiKey::AllowNegativeStock, true, $admin);
        $prefs->set(PreferensiKey::CompanyName, 'Example Co', $admin);

        $this->assertSame(120, $prefs->get(PreferensiKey::AgingRangeDays));
        $this->assertTrue(BusinessRule::AllowNegativeStock->isOn());
        $this->assertSame('Example Co', app(Preferensi::class)->get(PreferensiKey::CompanyName));

        $this->assertDatabaseHas('preferences', ['key' => 'other.aging_range_days', 'updated_by' => $admin->id]);

        $log = AuditLog::query()->where('action', 'preference_changed')->where('reference', PreferensiKey::AgingRangeDays->label())->first();
        $this->assertNotNull($log);
        $this->assertSame(90, $log->meta['old']);
        $this->assertSame(120, $log->meta['new']);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_an_unchanged_value_writes_nothing_and_a_blank_falls_back_to_the_default(): void
    {
        $this->actingAsAdmin();
        $prefs = app(Preferensi::class);

        $prefs->set(PreferensiKey::AgingRangeDays, 90);
        $this->assertSame(0, AuditLog::query()->where('action', 'preference_changed')->count());

        $prefs->set(PreferensiKey::AgingRangeDays, 45);
        $prefs->set(PreferensiKey::AgingRangeDays, '');
        $this->assertSame(90, $prefs->get(PreferensiKey::AgingRangeDays));
    }

    public function test_text_lists_keep_their_slots(): void
    {
        $this->actingAsAdmin();
        $prefs = app(Preferensi::class);

        $prefs->set(PreferensiKey::TransactionExtraColumns, ['Project', null, 'Lot']);

        $this->assertSame(['Project', null, 'Lot'], $prefs->get(PreferensiKey::TransactionExtraColumns));
        $this->assertCount(10, $prefs->get(PreferensiKey::ItemExtraColumns));
    }
}
