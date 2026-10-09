<?php

namespace Tests\Feature\Client\Ops;

use App\Client\Domain\Ops\Backup\BackupCipher;
use App\Client\Domain\Ops\Backup\BackupRunner;
use App\Client\Domain\Ops\Health\OpsHealth;
use App\Client\Filament\Pages\Operations;
use App\Client\Jobs\RunBackup;
use App\Client\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The Operations screen: health, backups, integrity findings on one page; a backup queued from it; administrators only. */
class OpsScreensTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    public function test_the_screen_shows_health_backups_and_integrity_and_queues_a_backup(): void
    {
        OpsHealth::beat();
        BackupRun::query()->create(['started_at' => now()->subHour(), 'status' => BackupRun::VERIFIED, 'disk' => 's3', 'offsite' => true, 'database_bytes' => 2048]);
        BackupRun::query()->create(['started_at' => now()->subDay(), 'status' => BackupRun::FAILED, 'disk' => 's3', 'offsite' => true, 'error' => 'pg_dump failed: boom']);
        $this->stock($this->gudangJakarta, 5);
        DB::table('item_costs')->where('item_id', $this->item->id)->update(['qty_on_hand' => 999]);
        Queue::fake();

        Livewire::test(Operations::class)->assertOk()
            ->assertSee('Scheduler')->assertSee('Last heartbeat')->assertSee('stored off this machine')
            ->assertSee('Verified')->assertSee('Failed')->assertSee('pg_dump failed: boom')->assertSee('2 KiB')
            ->assertSee('[stock]')->assertSee('cache 999.0000')
            ->callAction('backup')->assertNotified('Backup queued');
        Queue::assertPushed(RunBackup::class);
    }

    public function test_the_queued_backup_job_runs_the_runner_and_prunes(): void
    {
        config()->set('ops.backup.encryption_key', BackupCipher::generateKey());
        config()->set('ops.backup.disk', 'backups');
        config()->set('ops.backup.include_files', false);
        config()->set('filesystems.disks.backups', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backups')]);
        Storage::fake('backups');

        (new RunBackup('from the test'))->handle(app(BackupRunner::class));
        $run = BackupRun::query()->sole();
        $this->assertTrue($run->isVerified());
        $this->assertSame('from the test', $run->note);
    }

    public function test_only_an_administrator_opens_operations(): void
    {
        $this->get(Operations::getUrl())->assertOk();
        foreach ([$this->sales, $this->finance, $this->inventory] as $user) {
            $this->actingAs($user);
            $this->freshRequest();
            $this->get(Operations::getUrl())->assertForbidden();
        }
    }
}
