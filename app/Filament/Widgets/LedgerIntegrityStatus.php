<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Integrity\IntegrityFinding;
use App\Domain\Integrity\LedgerIntegrity;
use Filament\Widgets\Widget;

/**
 * Says something only when a ledger has stopped proving itself.
 *
 * Same posture as BackupStatus, and the same reasoning: **silent when
 * healthy**, because a green tick that is always there stops being read within
 * a week and is then decoration on the one morning it turns red. The nightly
 * job is what does the watching; this is for the case where the notification
 * was missed, dismissed, or landed while somebody was on leave.
 *
 * Above the work queues when it appears. A day of unapproved orders is a bad
 * day; stock that no longer matches its own kartu stok is a different category
 * of problem, and every figure computed from it — margin, valuation, what the
 * warehouse thinks it can ship — is suspect until it is explained.
 */
class LedgerIntegrityStatus extends Widget
{
    // Below BackupStatus: if both fire, "we have no backups" is read first.
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.ledger-integrity-status';

    public static function canView(): bool
    {
        /*
         * The Owner, matching who the nightly job notifies. A drift means
         * something wrote outside the domain classes — a question about the
         * system, not a task Finance or the warehouse can act on, and a
         * warning shown to somebody who cannot fix it trains them to ignore
         * warnings.
         */
        if (! (auth()->user()?->role()->canViewAuditLog() ?? false)) {
            return false;
        }

        return static::findings() !== [];
    }

    /**
     * Computed once per request.
     *
     * canView() and the view both need it, and this walks every SKU in every
     * region — running it twice to render one panel would be paid for on
     * every dashboard load by everybody, including the days it finds nothing.
     *
     * @return list<IntegrityFinding>
     */
    public static function findings(): array
    {
        static $memo = null;

        return $memo ??= app(LedgerIntegrity::class)->findings();
    }

    /** @return list<IntegrityFinding> */
    public function getFindings(): array
    {
        return static::findings();
    }
}
