<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Debt\DebtNotices;
use App\Client\Mail\DebtNoticeMessage;
use App\Client\Models\DebtNotice;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The aging notice: once per invoice older than the notice days, by email to the customer and the team, in the company's language. */
class DebtNoticeTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        Mail::fake();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
        app(Preferensi::class)->set(PreferensiKey::CreditNoticeDays, 120);
        app(Preferensi::class)->set(PreferensiKey::CreditFreezeDays, 150);
        $this->customer->forceFill(['email' => 'toko@example.test'])->save();
    }

    public function test_only_unpaid_invoices_past_the_notice_days_are_due_and_each_is_told_once(): void
    {
        $old = $this->invoice(1, 100_000, date: today()->subDays(121)->toDateString());
        $this->invoice(1, 100_000, date: today()->subDays(119)->toDateString());
        $paid = $this->invoice(1, 100_000, date: today()->subDays(130)->toDateString());
        $paid->forceFill(['payment_status' => 'paid'])->save();

        $this->assertSame([$old->id], app(DebtNotices::class)->due()->pluck('id')->all());

        $this->artisan('central:debt-notices')->assertSuccessful();
        $this->artisan('central:debt-notices')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(DebtNoticeMessage::class, function (DebtNoticeMessage $mail) use ($old): bool {
            return $mail->invoice->is($old) && $mail->days === 121 && $mail->hasTo('toko@example.test') && $mail->hasTo($this->sales->email) && $mail->hasTo($this->marketing->email);
        });
        $notice = DebtNotice::query()->sole();
        $this->assertSame($old->id, $notice->sales_invoice_id);
        $this->assertSame(121, $notice->days);
        $this->assertEqualsCanonicalizing(['toko@example.test', $this->sales->email, $this->marketing->email], $notice->sent_to);
        $this->assertSame([], app(DebtNotices::class)->due()->all(), 'nothing left to send');
    }

    public function test_the_mail_names_the_balance_and_the_day_the_account_freezes(): void
    {
        $old = $this->invoice(1, 100_000, date: today()->subDays(121)->toDateString());

        app(DebtNotices::class)->send($old);

        Mail::assertSent(DebtNoticeMessage::class, function (DebtNoticeMessage $mail) use ($old): bool {
            $text = $mail->render();

            return str_contains($text, $old->number) && str_contains($text, 'Rp 111.000') && str_contains($text, $old->trans_date->copy()->addDays(151)->format('j M Y'));
        });
        $this->assertNull(app(DebtNotices::class)->send($old->fresh()), 'sent before');
        Mail::assertSentCount(1);
    }

    public function test_nobody_to_tell_still_marks_the_invoice_and_notice_days_off_sends_nothing(): void
    {
        $this->customer->forceFill(['email' => null, 'sales_user_id' => null, 'marketing_user_id' => null])->save();
        $old = $this->invoice(1, 100_000, date: today()->subDays(121)->toDateString());

        app(DebtNotices::class)->send($old);
        Mail::assertNothingSent();
        $this->assertSame([], DebtNotice::query()->sole()->sent_to);

        app(Preferensi::class)->set(PreferensiKey::CreditNoticeDays, 0);
        $this->invoice(1, 100_000, date: today()->subDays(160)->toDateString());
        $this->assertCount(0, app(DebtNotices::class)->due());
    }
}
