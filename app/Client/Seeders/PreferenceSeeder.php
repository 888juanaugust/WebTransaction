<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use Illuminate\Database\Seeder;

/**
 * Central sells on credit: every order is approved before it ships, and
 * debt is noticed at 120 days and frozen after 150 (CLAUDE.md, debt terms).
 * Set once on a fresh installation; a company that changed them on the
 * Business Rules tab keeps its values. The test suite keeps the base's
 * defaults, so the base's own tests stay as written.
 */
class PreferenceSeeder extends Seeder
{
    public const NOTICE_DAYS = 120;

    public const FREEZE_DAYS = 150;

    public function run(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $prefs = app(Preferensi::class);
        if (! $prefs->isOn(PreferensiKey::SalesOrderApproval) && (int) $prefs->get(PreferensiKey::CreditFreezeDays) === 0) {
            $prefs->setMany([
                PreferensiKey::SalesOrderApproval->value => true,
                PreferensiKey::CreditNoticeDays->value => self::NOTICE_DAYS,
                PreferensiKey::CreditFreezeDays->value => self::FREEZE_DAYS,
            ]);
        }
    }
}
