<?php

namespace Tests\Feature\Client\Ops;

use App\Client\Domain\Ops\Backup\BackupCipher;
use App\Client\Domain\Ops\Backup\BackupHealth;
use App\Client\Domain\Ops\Backup\BackupRunner;
use App\Client\Domain\Ops\Backup\DatabaseDumper;
use App\Client\Domain\Ops\Backup\FileArchiver;
use App\Client\Models\BackupRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Backups: the one test that matters is the restore of a real dump into a scratch database; the rest supports it. */
class BackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ops.backup.encryption_key', BackupCipher::generateKey());
        config()->set('ops.backup.disk', 'backups');
        config()->set('ops.backup.include_files', false);
        config()->set('filesystems.disks.backups', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backups')]);
        Storage::fake('backups');
        // A second connection that commits: pg_dump is another process and sees committed rows only.
        config()->set('database.connections.committed', config('database.connections.pgsql'));
    }

    protected function tearDown(): void
    {
        DB::connection('committed')->table('site_settings')->whereIn('key', ['backup.before', 'backup.after'])->delete();
        DB::purge('committed');
        parent::tearDown();
    }

    private function commitSetting(string $key): void
    {
        DB::connection('committed')->table('site_settings')->insert(['key' => $key, 'value' => json_encode('x'), 'updated_at' => now()]);
    }

    public function test_a_backup_can_be_restored_into_another_database(): void
    {
        $this->commitSetting('backup.before');
        $run = app(BackupRunner::class)->run('restore drill');
        $this->assertTrue($run->isVerified());
        $this->commitSetting('backup.after');

        $dumper = DatabaseDumper::fromConfig();
        $scratch = 'central_restore_test';
        $dumper->runOnServer("DROP DATABASE IF EXISTS {$scratch}");
        $dumper->runOnServer("CREATE DATABASE {$scratch}");
        try {
            $this->artisan('central:restore', ['--into' => $scratch, '--force' => true])->assertSuccessful()->expectsOutputToContain("Restored into {$scratch}");

            config()->set('database.connections.scratch', array_merge(config('database.connections.pgsql'), ['database' => $scratch]));
            $keys = DB::connection('scratch')->table('site_settings')->pluck('key')->all();
            $this->assertContains('backup.before', $keys);
            $this->assertNotContains('backup.after', $keys, 'the restored rows came from the backup, not the live database');
            $this->assertFileDoesNotExist(storage_path('app/backup-scratch/restore-'.$run->id.'.sql'), 'the plaintext does not outlive the command');
        } finally {
            DB::purge('scratch');
            $dumper->runOnServer("DROP DATABASE IF EXISTS {$scratch}");
        }
    }

    public function test_the_live_database_is_restored_only_with_force_and_the_artefact_is_unreadable_without_the_key(): void
    {
        $this->commitSetting('backup.before');
        $run = app(BackupRunner::class)->run();

        $this->artisan('central:restore')->assertFailed()->expectsOutputToContain('only with --force');
        $stored = Storage::disk('backups')->get($run->database_path);
        $this->assertStringStartsWith(BackupCipher::MAGIC, $stored);
        $this->assertStringNotContainsString('backup.before', $stored);
        $this->assertStringNotContainsString('CREATE TABLE', $stored);
    }

    public function test_a_run_reads_back_what_it_wrote_and_a_destination_that_loses_it_fails_the_run(): void
    {
        $run = app(BackupRunner::class)->run();
        $this->assertSame(BackupRun::VERIFIED, $run->status);
        $this->assertGreaterThan(0, $run->verified_bytes);
        Storage::disk('backups')->assertExists($run->database_path);

        $losing = Mockery::mock(Storage::disk('backups'))->makePartial();
        $losing->shouldReceive('readStream')->andReturn(null);
        Storage::set('backups', $losing);
        try {
            app(BackupRunner::class)->run();
            $this->fail('a lost artefact passed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot read it back', $e->getMessage());
        }
        $this->assertSame(BackupRun::FAILED, BackupRun::query()->latest('id')->first()->status);
    }

    public function test_an_empty_dump_a_missing_binary_and_a_missing_key_are_refused_and_recorded(): void
    {
        config()->set('ops.backup.pg_dump', '/bin/true');
        try {
            app(BackupRunner::class)->run();
            $this->fail('empty dump');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        config()->set('ops.backup.pg_dump', '/nonexistent/pg_dump');
        $this->artisan('central:backup')->assertFailed()->expectsOutputToContain('Backup failed');
        $this->assertSame(2, BackupRun::query()->where('status', BackupRun::FAILED)->whereNotNull('error')->whereNotNull('finished_at')->count());

        config()->set('ops.backup.encryption_key', '');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BACKUP_ENCRYPTION_KEY is not set');
        app(BackupCipher::class);
    }

    public function test_the_kept_files_travel_too_and_an_empty_archive_is_refused(): void
    {
        $root = storage_path('framework/testing/backup-files');
        @mkdir($root.'/price-lists', 0755, true);
        file_put_contents($root.'/price-lists/harga.xlsx', 'supplier workbook bytes');
        config()->set('ops.backup.include_files', true);
        config()->set('ops.backup.files_root', $root);
        try {
            $run = app(BackupRunner::class)->run();
            $this->assertNotNull($run->files_path);
            $this->assertGreaterThan(0, $run->files_bytes);
            Storage::disk('backups')->assertExists($run->files_path);

            unlink($root.'/price-lists/harga.xlsx');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no files in it');
            (new FileArchiver($root))->archiveTo(BackupRunner::scratchPath('empty.tar'));
        } finally {
            @unlink($root.'/price-lists/harga.xlsx');
            @rmdir($root.'/price-lists');
            @rmdir($root);
            @unlink(BackupRunner::scratchPath('empty.tar'));
        }
    }

    public function test_pruning_keeps_the_daily_window_one_a_month_and_always_the_newest_copy(): void
    {
        $runner = app(BackupRunner::class);
        config()->set('ops.backup.keep_daily', 7);
        config()->set('ops.backup.keep_monthly', 12);
        $january = $this->travelTo(Carbon::parse('2026-01-15 02:15:00'), fn () => $runner->run());
        $februaryFirst = $this->travelTo(Carbon::parse('2026-02-10 02:15:00'), fn () => $runner->run());
        $februarySecond = $this->travelTo(Carbon::parse('2026-02-20 02:15:00'), fn () => $runner->run());
        $recent = $this->travelTo(Carbon::parse('2026-08-17 02:15:00'), fn () => $runner->run());
        $this->travelTo('2026-08-18 02:15:00');

        $this->assertSame(1, $runner->prune());
        $this->assertNotNull(BackupRun::query()->find($january->id));
        $this->assertNotNull(BackupRun::query()->find($februarySecond->id), 'the newest of the month survives');
        $this->assertNull(BackupRun::query()->find($februaryFirst->id));
        Storage::disk('backups')->assertMissing($februaryFirst->database_path);
        $this->assertNotNull(BackupRun::query()->find($recent->id));

        config()->set('ops.backup.keep_daily', 0);
        config()->set('ops.backup.keep_monthly', 0);
        $runner->prune();
        $this->assertNotNull(BackupRun::query()->find($recent->id), 'never the newest good copy');
        Storage::disk('backups')->assertExists($recent->database_path);
        $this->travelBack();
    }

    public function test_health_says_never_stale_failing_local_and_ok(): void
    {
        $health = app(BackupHealth::class);
        $this->assertSame(BackupHealth::NEVER, $health->state());

        config()->set('ops.backup.disk', 'local');
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-08-10 02:15:00'), fn () => app(BackupRunner::class)->run());
        $this->assertSame(BackupHealth::STALE, $health->state());

        $this->travelTo('2026-08-10 12:00:00');
        $this->assertSame(BackupHealth::LOCAL, $health->state(), 'verified but on this machine');
        config()->set('ops.backup.local_is_offsite', true);
        app(BackupRunner::class)->run();
        $this->assertSame(BackupHealth::OK, $health->state());
        $this->assertTrue($health->isHealthy());

        config()->set('ops.backup.pg_dump', '/nonexistent');
        $this->travelTo('2026-08-11 02:15:00');
        try {
            app(BackupRunner::class)->run();
        } catch (RuntimeException) {
        }
        $this->assertSame(BackupHealth::FAILING, $health->state());
        $this->assertStringContainsString('FAILED', $health->message());
        $this->travelBack();
    }

    public function test_the_key_command_prints_a_usable_key_and_the_schedule_runs_nightly(): void
    {
        $this->artisan('central:backup-key')->assertSuccessful()->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=');
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'central:backup'));
        $this->assertCount(1, $events);
        $this->assertSame('15 2 * * *', $events->first()->expression);
    }
}
