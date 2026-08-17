<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Backup\BackupCipher;
use App\Domain\Backup\BackupHealth;
use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\DatabaseDumper;
use App\Domain\Backup\FileArchiver;
use App\Models\BackupRun;
use App\Models\Product;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Backups, and the one test that matters.
 *
 * CLAUDE.md asks for a restore to be tested before launch. A test that only
 * checks a file appeared would satisfy the letter of that and none of the
 * point — the question is never "did we write something", it is "can we get
 * the business back". So the central test here dumps a real database,
 * encrypts it, restores it into a scratch database and reads the rows out of
 * that. Everything else in this file supports it.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('backup.encryption_key', BackupCipher::generateKey());
        config()->set('backup.disk', 'backups');
        config()->set('backup.include_files', false);

        Storage::fake('backups');
    }

    // ------------------------------------------------------- the whole point

    public function test_a_backup_can_actually_be_restored(): void
    {
        /*
         * The end to end. Everything else is scaffolding around this: take a
         * real backup of a real database, then restore it somewhere else and
         * read the rows back out. A backup nobody has restored is a
         * hypothesis, and the only way to stop it being one is to restore it.
         *
         * The rows are written on a **second, committed connection** rather
         * than through the usual factories. That is not a workaround; it is
         * the property being tested. `pg_dump` is a separate process, so it
         * sees committed data and nothing else — the rows this test's own
         * transaction is holding open would not be in the backup, and neither
         * would a real order somebody was halfway through placing.
         */
        $this->commitProduct('YH-BK-1', 'Sebelum backup');

        $run = app(BackupRunner::class)->run('uji pemulihan');

        $this->assertTrue($run->isVerified());

        // Committed *after* the backup, so a restore that silently did nothing
        // would still look right without this.
        $this->commitProduct('YH-BK-2', 'Sesudah backup');

        $dumper = DatabaseDumper::fromConfig();
        $scratch = 'webtransaction_restore_test';

        $dumper->runOnServer("DROP DATABASE IF EXISTS {$scratch}");
        $dumper->runOnServer("CREATE DATABASE {$scratch}");

        try {
            $plain = storage_path('app/backup-scratch/restore-assert.sql');
            app(BackupRunner::class)->decryptTo($run->database_path, $plain, $run->disk);

            $dumper->restoreFrom($plain, $scratch);
            @unlink($plain);

            $restored = $this->productsIn($scratch);

            // The row that existed when the backup was taken is there…
            $this->assertContains('YH-BK-1', $restored);
            // …and the one committed afterwards is not, which is what proves
            // the restored data came out of the backup rather than out of the
            // live database this test is still connected to.
            $this->assertNotContains('YH-BK-2', $restored);
        } finally {
            $dumper->runOnServer("DROP DATABASE IF EXISTS {$scratch}");
            $this->deleteCommittedProducts(['YH-BK-1', 'YH-BK-2']);
        }
    }

    // -------------------------------------------------------- taking one

    public function test_a_run_reads_back_what_it_wrote_before_calling_itself_done(): void
    {
        /*
         * Writing a file proves the disk accepted bytes, which is not the
         * question anybody has. The only success state is `verified`, and it
         * means the artefact was downloaded and decrypted again.
         */
        $run = app(BackupRunner::class)->run();

        $this->assertSame(BackupRun::STATUS_VERIFIED, $run->status);
        $this->assertGreaterThan(0, $run->database_bytes);
        $this->assertGreaterThan(0, $run->verified_bytes);

        Storage::disk('backups')->assertExists($run->database_path);
    }

    public function test_the_stored_artefact_is_not_readable_without_the_key(): void
    {
        // It leaves the machine and lands somewhere we do not control. Every
        // customer's NPWP and every price we charge is in it.
        Product::factory()->create(['kode' => 'RAHASIA-1', 'description' => 'Harga rahasia']);

        $run = app(BackupRunner::class)->run();

        $stored = Storage::disk('backups')->get($run->database_path);

        $this->assertStringNotContainsString('RAHASIA-1', $stored);
        $this->assertStringNotContainsString('Harga rahasia', $stored);
        $this->assertStringStartsWith(BackupCipher::MAGIC, $stored);
    }

    public function test_a_destination_that_accepts_the_write_and_loses_it_fails_the_run(): void
    {
        /*
         * The reason this system reads its own artefact back, and the failure
         * it exists to catch. Storage that takes the bytes and cannot return
         * them looks identical to storage that worked — right up until the
         * night somebody needs the file.
         *
         * Without the read-back, this run would be recorded `verified` and the
         * dashboard would go green over nothing at all.
         */
        $real = Storage::disk('backups');

        $losing = \Mockery::mock($real)->makePartial();
        $losing->shouldReceive('readStream')->andReturn(null);

        Storage::set('backups', $losing);

        try {
            app(BackupRunner::class)->run();
            $this->fail('Expected a destination that loses the artefact to fail the run.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot read it back', $e->getMessage());
        }

        $this->assertSame(BackupRun::STATUS_FAILED, BackupRun::query()->sole()->status);
    }

    public function test_a_dump_that_produces_nothing_is_refused(): void
    {
        /*
         * pg_dump can exit zero having written nothing — a wrong database name
         * against a server that has one, for instance. Storing that would
         * replace a good backup with a valid empty one, and the retention
         * window would eventually delete the last real copy in its favour.
         *
         * `/bin/true` stands in: exits successfully, writes no file.
         */
        config()->set('backup.pg_dump', '/bin/true');

        try {
            app(BackupRunner::class)->run();
            $this->fail('Expected an empty dump to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $this->assertSame(BackupRun::STATUS_FAILED, BackupRun::query()->sole()->status);
    }

    public function test_an_archive_with_nothing_in_it_is_refused(): void
    {
        // Same reasoning as the empty dump: a valid archive of nothing is
        // worse than no archive, because it looks like it worked.
        $empty = storage_path('app/backup-empty-test');

        @mkdir($empty, 0755, true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no files in it');

            (new FileArchiver($empty))->archiveTo(storage_path('app/backup-scratch/empty.tar'));
        } finally {
            $this->deleteTree($empty);
        }
    }

    public function test_a_run_with_no_key_is_refused_rather_than_written_in_the_clear(): void
    {
        /*
         * The alternative — write it unencrypted and warn — produces exactly
         * the file we were trying not to produce, and the warning scrolls
         * past. Better to have no backup than a plaintext one nobody knows is
         * plaintext.
         */
        config()->set('backup.encryption_key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY is not set');

        app(BackupCipher::class);
    }

    public function test_a_failed_run_is_recorded_rather_than_vanishing(): void
    {
        /*
         * A table of successes cannot tell "nothing has run since Tuesday"
         * from "everything has failed since Tuesday", and those need different
         * responses. So failures are rows too.
         */
        config()->set('backup.pg_dump', '/nonexistent/pg_dump');

        try {
            app(BackupRunner::class)->run();
            $this->fail('Expected the missing pg_dump to fail the run.');
        } catch (RuntimeException) {
            // Expected.
        }

        $run = BackupRun::query()->sole();

        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_local_destination_is_recorded_as_not_having_left_the_building(): void
    {
        /*
         * The machine is the thing that fails. A copy sitting beside the
         * original protects against a dropped table and nothing else, and the
         * dashboard has to be able to say so.
         */
        config()->set('backup.disk', 'local');
        config()->set('backup.local_is_offsite', false);
        Storage::fake('local');

        $run = app(BackupRunner::class)->run();

        $this->assertTrue($run->isVerified());
        $this->assertFalse($run->offsite);
        $this->assertSame(BackupHealth::LOCAL, app(BackupHealth::class)->state());
    }

    // ------------------------------------------------------------- retention

    public function test_old_runs_are_pruned_and_their_files_deleted(): void
    {
        $runner = app(BackupRunner::class);

        config()->set('backup.keep_daily', 7);
        config()->set('backup.keep_monthly', 0);

        $old = $this->travelTo(Carbon::parse('2026-01-15 02:15:00'), fn () => $runner->run());
        $recent = $this->travelTo(Carbon::parse('2026-08-17 02:15:00'), fn () => $runner->run());

        $this->travelTo('2026-08-18 02:15:00');

        $this->assertSame(1, $runner->prune());

        $this->assertNull(BackupRun::query()->find($old->id));
        $this->assertNotNull(BackupRun::query()->find($recent->id));
        Storage::disk('backups')->assertMissing($old->database_path);
        Storage::disk('backups')->assertExists($recent->database_path);
    }

    public function test_one_backup_a_month_is_kept_beyond_the_daily_window(): void
    {
        /*
         * The mistakes that need an old backup — a bad price import, a wrong
         * figure published — are usually noticed weeks later, not the next
         * morning. A fortnight of dailies would already have dropped them.
         */
        $runner = app(BackupRunner::class);

        config()->set('backup.keep_daily', 7);
        config()->set('backup.keep_monthly', 12);

        $january = $this->travelTo(Carbon::parse('2026-01-15 02:15:00'), fn () => $runner->run());
        $februaryFirst = $this->travelTo(Carbon::parse('2026-02-10 02:15:00'), fn () => $runner->run());
        $februarySecond = $this->travelTo(Carbon::parse('2026-02-20 02:15:00'), fn () => $runner->run());

        $this->travelTo('2026-08-18 02:15:00');

        $runner->prune();

        // Newest of each month survives; the second February one does not.
        $this->assertNotNull(BackupRun::query()->find($january->id));
        $this->assertNotNull(BackupRun::query()->find($februarySecond->id));
        $this->assertNull(BackupRun::query()->find($februaryFirst->id));
    }

    public function test_pruning_never_touches_the_newest_good_copy(): void
    {
        // Deleting first would mean a bad night costs the good copy too.
        $runner = app(BackupRunner::class);

        config()->set('backup.keep_daily', 0);
        config()->set('backup.keep_monthly', 0);

        $only = $runner->run();

        $runner->prune();

        $this->assertNotNull(BackupRun::query()->find($only->id));
        Storage::disk('backups')->assertExists($only->database_path);
    }

    // ---------------------------------------------------------------- health

    public function test_health_says_never_when_nothing_has_ever_run(): void
    {
        $health = app(BackupHealth::class);

        $this->assertSame(BackupHealth::NEVER, $health->state());
        $this->assertFalse($health->isHealthy());
        $this->assertStringContainsString('Belum pernah', $health->message());
    }

    public function test_health_says_stale_when_the_last_good_one_is_old(): void
    {
        config()->set('backup.stale_after_hours', 30);
        config()->set('backup.local_is_offsite', true);

        $this->travelTo(Carbon::parse('2026-08-10 02:15:00'), fn () => app(BackupRunner::class)->run());

        $this->travelTo('2026-08-17 09:00:00');

        $this->assertSame(BackupHealth::STALE, app(BackupHealth::class)->state());
    }

    public function test_a_failure_after_a_good_run_reads_as_failing_not_stale(): void
    {
        /*
         * Different problems, different responses. "Stale" sends somebody to
         * look at the scheduler; "failing" means the scheduler is fine and the
         * destination is not.
         */
        config()->set('backup.local_is_offsite', true);

        $this->travelTo(Carbon::parse('2026-08-17 02:15:00'), fn () => app(BackupRunner::class)->run());

        $this->travelTo('2026-08-17 06:00:00');

        BackupRun::create([
            'started_at' => now(),
            'finished_at' => now(),
            'status' => BackupRun::STATUS_FAILED,
            'disk' => 'backups',
            'error' => 'destination refused the write',
        ]);

        $health = app(BackupHealth::class);

        $this->assertSame(BackupHealth::FAILING, $health->state());
        $this->assertStringContainsString('destination refused the write', $health->message());
    }

    public function test_health_is_ok_only_when_recent_verified_and_off_the_machine(): void
    {
        config()->set('backup.local_is_offsite', true);

        $this->travelTo(Carbon::parse('2026-08-17 02:15:00'), fn () => app(BackupRunner::class)->run());
        $this->travelTo('2026-08-17 09:00:00');

        $this->assertSame(BackupHealth::OK, app(BackupHealth::class)->state());
        $this->assertTrue(app(BackupHealth::class)->isHealthy());
    }

    // ----------------------------------------------------------------- files

    public function test_the_kept_forever_files_go_in_too(): void
    {
        /*
         * The raw supplier price lists and the faktur pajak exports as filed.
         * Neither can be regenerated from the database — the first was never
         * in it, and the second would come back as whatever today's code
         * produces rather than what was actually uploaded.
         */
        $root = storage_path('app/backup-files-test');

        @mkdir($root.'/harga', 0755, true);
        file_put_contents($root.'/harga/PL_ASLI.xlsx', 'isi file asli');

        config()->set('backup.include_files', true);
        config()->set('backup.files_root', $root);

        try {
            $run = app(BackupRunner::class)->run();

            $this->assertNotNull($run->files_path);
            $this->assertGreaterThan(0, $run->files_bytes);
            Storage::disk('backups')->assertExists($run->files_path);

            $tar = storage_path('app/backup-scratch/files-assert.tar');
            app(BackupRunner::class)->decryptTo($run->files_path, $tar, $run->disk);

            $out = storage_path('app/backup-files-restored');
            app(FileArchiver::class)->extractTo($tar, $out);

            $this->assertSame('isi file asli', file_get_contents($out.'/harga/PL_ASLI.xlsx'));
        } finally {
            $this->deleteTree($root);
            $this->deleteTree(storage_path('app/backup-files-restored'));
            @unlink(storage_path('app/backup-scratch/files-assert.tar'));
        }
    }

    // --- helpers ------------------------------------------------------------

    /**
     * Write a product on a connection outside this test's transaction.
     *
     * RefreshDatabase holds an open transaction for the whole test and rolls
     * it back afterwards, so nothing written through the usual connection is
     * ever committed — and `pg_dump`, being another process entirely, would
     * back up a database that appears not to contain it.
     */
    private function commitProduct(string $kode, string $description): void
    {
        $attributes = Product::factory()->make([
            'kode' => $kode,
            'description' => $description,
        ])->getAttributes();

        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        $this->committed()->table('products')->insert($attributes);
    }

    /** @param  list<string>  $codes */
    private function deleteCommittedProducts(array $codes): void
    {
        $this->committed()->table('products')->whereIn('kode', $codes)->delete();

        DB::purge('committed_probe');
    }

    /** A second connection to the same database, with no transaction on it. */
    private function committed(): Connection
    {
        config()->set('database.connections.committed_probe', config('database.connections.pgsql'));

        return DB::connection('committed_probe');
    }

    /** @return list<string> product codes in another database */
    private function productsIn(string $database): array
    {
        $config = config('database.connections.pgsql');
        $config['database'] = $database;

        config()->set('database.connections.restore_probe', $config);

        try {
            return collect(DB::connection('restore_probe')->select('SELECT kode FROM products ORDER BY kode'))
                ->pluck('kode')
                ->all();
        } finally {
            DB::purge('restore_probe');
        }
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }

        @rmdir($dir);
    }
}
