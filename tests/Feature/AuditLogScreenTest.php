<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Filament\Pages\LogAudit;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who did what, and what it used to be.
 *
 * Everything money-affecting has been written here since the beginning, for an
 * audience with no way to read it. The properties worth holding: only the owner
 * can look, nothing on the page can alter a row, and the change summary shows
 * the field somebody actually altered rather than two whole payloads to compare
 * by eye.
 */
class AuditLogScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->role(Role::Owner)->create(['name' => 'Pemilik']);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        /*
         * Owner only. This screen carries the old and new value of everything
         * anybody overrode — prices, credit limits — so it is the one place
         * that leaks every kind of data the role separation elsewhere exists
         * to keep apart.
         */
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(LogAudit::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'pemilik' => [Role::Owner, true],
            'keuangan' => [Role::Finance, false],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_it_lists_what_was_written(): void
    {
        app(AuditLogger::class)->log(
            action: 'credit_limit_override',
            subject: Company::factory()->create(['nama' => 'CV Sinar Distribusi']),
            oldValue: ['credit_limit_rupiah' => 50_000_000],
            newValue: ['credit_limit_rupiah' => 80_000_000],
            actor: $this->owner,
            alasan: 'Disetujui pemilik lewat telepon',
        );

        Livewire::actingAs($this->owner)
            ->test(LogAudit::class)
            ->assertOk()
            ->assertSee('Limit kredit ditimpa')
            ->assertSee('Disetujui pemilik lewat telepon');
    }

    public function test_the_change_summary_names_only_what_moved(): void
    {
        /*
         * Printing both payloads whole makes the one field somebody altered
         * impossible to find, which is the only reason anybody opened the row.
         */
        $log = app(AuditLogger::class)->log(
            action: 'price_override',
            oldValue: ['harga' => 412_500, 'kode' => 'YH-1001'],
            newValue: ['harga' => 380_000, 'kode' => 'YH-1001'],
            actor: $this->owner,
        );

        $summary = LogAudit::changeSummary($log);

        $this->assertStringContainsString('harga: 412.500 → 380.000', $summary);
        $this->assertStringNotContainsString('kode', $summary);
    }

    public function test_a_first_value_reads_as_a_value_rather_than_a_change(): void
    {
        // Most rows record something happening, not something being altered.
        // "nomor: → INV-1" would be nonsense.
        $log = app(AuditLogger::class)->log(
            action: 'invoice_issued',
            newValue: ['nomor' => 'INV-202608-0001'],
            actor: $this->owner,
        );

        $this->assertSame('nomor: INV-202608-0001', LogAudit::changeSummary($log));
    }

    public function test_a_row_that_changed_nothing_says_nothing(): void
    {
        $log = app(AuditLogger::class)->log(
            action: 'order_transition',
            oldValue: ['status' => 'draft'],
            newValue: ['status' => 'draft'],
            actor: $this->owner,
        );

        $this->assertNull(LogAudit::changeSummary($log));
    }

    public function test_overrides_and_reversals_are_marked_apart_from_routine_postings(): void
    {
        // "Show me every time somebody overruled the system" is the question an
        // audit log exists to answer.
        $this->assertTrue(LogAudit::isReversal('credit_limit_override'));
        $this->assertTrue(LogAudit::isReversal('accounting_period_reopened'));
        $this->assertTrue(LogAudit::isReversal('payment_reversed'));

        $this->assertFalse(LogAudit::isReversal('invoice_issued'));
        $this->assertFalse(LogAudit::isReversal('goods_receipt_posted'));
    }

    public function test_the_important_filter_narrows_to_those_rows(): void
    {
        $logger = app(AuditLogger::class);

        $logger->log(action: 'invoice_issued', actor: $this->owner);
        $logger->log(action: 'credit_limit_override', actor: $this->owner, alasan: 'Naik');

        Livewire::actingAs($this->owner)
            ->test(LogAudit::class)
            ->filterTable('hanya_penting')
            ->assertCanSeeTableRecords(AuditLog::query()->where('action', 'credit_limit_override')->get())
            ->assertCanNotSeeTableRecords(AuditLog::query()->where('action', 'invoice_issued')->get());
    }

    public function test_an_action_nobody_has_named_still_shows_its_own_key(): void
    {
        /*
         * A label map that silently swallows unknown keys means an action
         * added next year disappears from the screen that exists to catch it.
         */
        $this->assertSame('sesuatu yang baru', LogAudit::actionLabel('sesuatu_yang_baru'));
    }

    public function test_the_action_filter_offers_only_what_has_actually_happened(): void
    {
        // Fifty options for a system three weeks old is a dropdown nobody
        // reads. The list comes from the rows, not from the label map.
        app(AuditLogger::class)->log(action: 'invoice_issued', actor: $this->owner);

        $options = LogAudit::actionOptions();

        $this->assertSame(['invoice_issued' => 'Faktur terbit'], $options);
    }

    public function test_a_system_action_with_no_actor_still_lists(): void
    {
        // The webhook and the scheduler act with nobody behind them, and those
        // are exactly the rows somebody goes looking for after an incident.
        app(AuditLogger::class)->log(action: 'payment_received', actor: null);

        Livewire::actingAs($this->owner)
            ->test(LogAudit::class)
            ->assertOk()
            ->assertSee('Pembayaran diterima');
    }
}
