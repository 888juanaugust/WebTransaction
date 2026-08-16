<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Posting the same document twice at the same instant.
 *
 * Ledger::post() has two defences against a double posting and they are not
 * the same one twice. The look-up at the top handles the ordinary case — a
 * retried job, a second click. It cannot handle two callers who both look and
 * both find nothing, which is precisely what a queue worker and its retry do.
 * That case is settled by the unique index on (source_type, source_id, jenis),
 * and by the loser reading the winner's row instead of failing.
 *
 * Single-threaded tests cannot tell that recovery apart from never needing it,
 * so this forks real processes, holds them at a gate, and releases them
 * together — the same harness the stock reservation race uses.
 *
 * DatabaseMigrations rather than RefreshDatabase: RefreshDatabase wraps the
 * test in a transaction that is never committed, and a forked child on its own
 * connection would see an empty database.
 */
#[Group('concurrency')]
class JournalPostingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const RACERS = 4;

    private const GATE = 4243;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->company = Company::factory()->create();
    }

    public function test_four_processes_posting_one_document_produce_exactly_one_entry(): void
    {
        $outcomes = $this->racePostings();

        $this->assertSame(0, $outcomes['errored'], 'A racing poster failed instead of recovering.');
        $this->assertSame(self::RACERS, $outcomes['posted']);

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(3, JournalLine::query()->count());
    }

    public function test_the_one_surviving_entry_still_balances(): void
    {
        $this->racePostings();

        $entry = JournalEntry::query()->with('lines')->sole();

        $this->assertTrue($entry->isBalanced());
        $this->assertSame(11_100_000, (int) $entry->lines->sum('debit_rupiah'));
        $this->assertSame(11_100_000, (int) $entry->lines->sum('kredit_rupiah'));
        $this->assertTrue(app(Ledger::class)->isBalanced());
    }

    public function test_every_racer_is_handed_the_same_entry_id(): void
    {
        // Not just "one entry exists" — each caller has to come away with a
        // usable entry, because the document service that called it will go on
        // to reference what it was given.
        $ids = $this->racePostings()['ids'];

        $this->assertCount(self::RACERS, $ids);
        $this->assertCount(1, array_unique($ids));
        $this->assertSame(JournalEntry::query()->sole()->id, $ids[0]);
    }

    /**
     * Fork one process per racer and start them together.
     *
     * @return array{posted: int, errored: int, ids: list<int>}
     */
    private function racePostings(): array
    {
        /*
         * A Postgres advisory lock as a starting gun. The parent takes it
         * exclusively before forking; each child asks for it in shared mode
         * and blocks. Releasing grants every child at once, so they reach the
         * insert within microseconds of each other. Wall-clock alignment alone
         * was not tight enough to make the stock race fail with the lock
         * removed, and it would not be tight enough here either.
         */
        $gate = DB::connection();
        $gate->select('SELECT pg_advisory_lock('.self::GATE.')');

        $pids = [];
        $pipes = [];

        foreach (range(1, self::RACERS) as $n) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();

            if ($pid === -1) {
                $gate->select('SELECT pg_advisory_unlock('.self::GATE.')');
                $this->fail('Could not fork a process for the race.');
            }

            if ($pid === 0) {
                fclose($pair[0]);
                exit($this->childPost($pair[1]));
            }

            fclose($pair[1]);
            $pids[] = $pid;
            $pipes[] = $pair[0];
        }

        // Give the children time to reach the gate and queue behind it.
        usleep(400_000);
        $gate->select('SELECT pg_advisory_unlock('.self::GATE.')');

        $outcomes = ['posted' => 0, 'errored' => 0, 'ids' => []];

        foreach ($pids as $i => $pid) {
            $reported = trim((string) stream_get_contents($pipes[$i]));
            fclose($pipes[$i]);

            pcntl_waitpid($pid, $status);
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;

            if ($code === 0) {
                $outcomes['posted']++;
                $outcomes['ids'][] = (int) $reported;
            } else {
                $outcomes['errored']++;
            }
        }

        return $outcomes;
    }

    /**
     * Runs inside a forked child. Writes the entry id it was handed back down
     * the pipe and returns the process exit code: 0 posted, 1 threw.
     *
     * @param  resource  $pipe
     */
    private function childPost($pipe): int
    {
        // The inherited connection belongs to the parent; sharing one socket
        // across processes corrupts both sides of the conversation.
        DB::purge();
        DB::reconnect();

        // Queue at the gate. Released the instant the parent lets go.
        DB::select('SELECT pg_advisory_lock_shared('.self::GATE.')');
        DB::select('SELECT pg_advisory_unlock_shared('.self::GATE.')');

        try {
            $company = Company::findOrFail($this->company->id);

            $entry = app(Ledger::class)->post(
                JournalDraft::for($company, JournalEntry::JENIS_PENJUALAN, 'Penjualan')
                    ->debit(AccountCode::PIUTANG_USAHA, 11_100_000, company: $company)
                    ->kredit(AccountCode::PENJUALAN, 10_000_000)
                    ->kredit(AccountCode::PPN_KELUARAN, 1_100_000)
            );

            fwrite($pipe, (string) $entry->id);
            fclose($pipe);

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "child failed: {$e->getMessage()}\n");
            fclose($pipe);

            return 1;
        }
    }
}
