<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Postgres and PHP have to agree what time it is.
 *
 * They did not. PHP runs Asia/Jakarta; the connection left the server's own
 * timezone alone, which on the VPS and in CI is UTC. Measured on this box:
 *
 *     php now   2026-09-06 00:02:03
 *     pg  now   2026-09-05 17:02:03
 *
 * Seven hours, and — for seven hours out of every day — a different calendar
 * date. Nothing was visibly wrong, because every timestamp this system stores
 * is written by PHP. What made it worth fixing is that eleven tables carry
 * `DEFAULT CURRENT_TIMESTAMP`, evaluated by Postgres, on `timestamp without
 * time zone` columns that Eloquent then reads back as Jakarta. Among them:
 * payment_entries, journal_entries, stock_movements, audit_logs.
 *
 * The first bulk insert that omitted `created_at` — a backfill, an import, a
 * `DB::table(...)->insert()` written in a hurry — would have stamped the money
 * seven hours early and silently. A journal posted before 07:00 Jakarta would
 * carry the previous day's date, and on the first of a month that is a period
 * that may already be closed.
 *
 * One line of config, and the fix is worth a test because it is invisible: it
 * changes nothing anybody can see until the day it would have mattered.
 */
class DatabaseClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connection_runs_on_the_applications_timezone(): void
    {
        $this->assertSame(
            config('app.timezone'),
            DB::selectOne('SHOW timezone')->TimeZone,
            'Postgres harus memakai zona waktu yang sama dengan aplikasi.',
        );
    }

    public function test_the_two_clocks_agree_to_the_second(): void
    {
        $php = now();
        $pg = DB::selectOne('SELECT CURRENT_TIMESTAMP::timestamp AS t')->t;

        // Same wall clock, not merely the same instant: the column type these
        // defaults land in carries no offset, so a matching instant expressed
        // in a different zone is exactly the failure being guarded against.
        $this->assertLessThan(
            5,
            abs($php->diffInSeconds($pg)),
            "PHP {$php->toDateTimeString()} vs Postgres {$pg}",
        );
    }

    public function test_a_row_taking_the_database_default_is_stamped_on_the_application_clock(): void
    {
        /*
         * The concrete path. `audit_logs.created_at` defaults to
         * CURRENT_TIMESTAMP, so an insert that omits it is stamped by the
         * server — which is the case this whole test exists for. Before the
         * fix this row came back seven hours in the past, and today, on
         * yesterday's date.
         */
        DB::statement("INSERT INTO audit_logs (action) VALUES ('uji_jam_basis_data')");

        $stamped = DB::table('audit_logs')->where('action', 'uji_jam_basis_data')->value('created_at');

        $this->assertLessThan(
            5,
            abs(now()->diffInSeconds($stamped)),
            "Baris ber-default distempel {$stamped}, sementara aplikasi membaca ".now()->toDateTimeString(),
        );
    }
}
