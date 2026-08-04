<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Payments\PaymentLedger;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PriceListImports\PriceListImportResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The role matrix from CLAUDE.md, asserted rather than assumed.
 *
 * | Role      | Can                              | Cannot                          |
 * |-----------|----------------------------------|---------------------------------|
 * | Sales     | Create orders, see prices        | Override credit, confirm payment|
 * | Warehouse | Pick, ship, print surat jalan    | See prices or credit data       |
 * | Finance   | Confirm payments, manage credit  | Edit order line prices          |
 * | Owner     | Everything + audit log           | —                               |
 *
 * Until now this lived in the Role enum and in my own browser checks. A
 * refactor could have quietly widened any of it with the whole suite green,
 * and the failure would be a warehouse hand reading customer credit or a
 * finance clerk moving an invoice amount.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The full matrix, written out. Any capability added to Role without a row
     * here fails test_every_capability_is_covered below.
     *
     * @return array<string, array{0: Role, 1: array<string, bool>}>
     */
    public static function matrix(): array
    {
        return [
            'sales' => [Role::Sales, [
                'canSeePrices' => true,
                'canSeeCreditData' => true,
                'canCreateOrders' => true,
                'canConfirmPayment' => false,
                'canEditOrderPrices' => true,
                'canOverrideCreditLimit' => false,
                'canPickAndShip' => false,
                'canViewAuditLog' => false,
            ]],
            'warehouse' => [Role::Warehouse, [
                'canSeePrices' => false,
                'canSeeCreditData' => false,
                'canCreateOrders' => false,
                'canConfirmPayment' => false,
                'canEditOrderPrices' => false,
                'canOverrideCreditLimit' => false,
                'canPickAndShip' => true,
                'canViewAuditLog' => false,
            ]],
            'finance' => [Role::Finance, [
                'canSeePrices' => true,
                'canSeeCreditData' => true,
                'canCreateOrders' => false,
                'canConfirmPayment' => true,
                'canEditOrderPrices' => false,
                'canOverrideCreditLimit' => true,
                'canPickAndShip' => false,
                'canViewAuditLog' => false,
            ]],
            'owner' => [Role::Owner, [
                'canSeePrices' => true,
                'canSeeCreditData' => true,
                'canCreateOrders' => true,
                'canConfirmPayment' => true,
                'canEditOrderPrices' => true,
                'canOverrideCreditLimit' => true,
                'canPickAndShip' => true,
                'canViewAuditLog' => true,
            ]],
        ];
    }

    /**
     * @param  array<string, bool>  $expected
     */
    #[DataProvider('matrix')]
    public function test_the_role_matrix_holds(Role $role, array $expected): void
    {
        foreach ($expected as $capability => $allowed) {
            $this->assertSame(
                $allowed,
                $role->{$capability}(),
                "{$role->value}->{$capability}() should be ".($allowed ? 'true' : 'false')
            );
        }
    }

    /** A new capability on Role must be given a row in the matrix above. */
    public function test_every_capability_is_covered_by_the_matrix(): void
    {
        $declared = array_keys(self::matrix()['owner'][1]);

        $actual = collect((new \ReflectionClass(Role::class))->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn (\ReflectionMethod $m) => $m->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'can'))
            ->values()
            ->all();

        sort($declared);
        sort($actual);

        $this->assertSame(
            $actual,
            $declared,
            'Every can* method on Role needs a row in the matrix, or it is enforced but never checked.'
        );
    }

    // --- the hard rule ------------------------------------------------------

    /**
     * "Whoever confirms a payment must not be able to edit the invoice amount."
     *
     * The separation only means anything if no single non-owner role holds
     * both, so this asserts the two sets are disjoint rather than checking
     * Finance in isolation.
     */
    public function test_no_role_can_both_confirm_payment_and_move_the_amount_owed(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::Owner) {
                // The owner is the deliberate exception, and every action they
                // take is in the audit log they alone can read.
                continue;
            }

            $this->assertFalse(
                $role->canConfirmPayment() && $role->canEditOrderPrices(),
                "{$role->value} can both confirm a payment and edit prices — that is the "
                .'separation the spec calls a hard rule.'
            );
        }
    }

    public function test_only_finance_and_owner_may_post_a_manual_payment(): void
    {
        $company = Company::factory()->create();
        $invoice = Invoice::factory()->totalling(1_000_000)->create(['company_id' => $company->id]);
        $ledger = app(PaymentLedger::class);

        foreach ([Role::Sales, Role::Warehouse] as $role) {
            $actor = User::factory()->role($role)->create();

            try {
                $ledger->recordManualPayment($company, 1_000_000, $actor, $invoice);
                $this->fail("{$role->value} must not be able to post a payment.");
            } catch (LogicException $e) {
                $this->assertStringContainsString($role->value, $e->getMessage());
            }
        }

        // And the roles that may, do.
        foreach ([Role::Finance, Role::Owner] as $role) {
            $entry = $ledger->recordManualPayment(
                $company,
                1_000,
                User::factory()->role($role)->create(),
                $invoice,
            );

            $this->assertSame(1_000, $entry->amount_rupiah);
        }
    }

    public function test_only_finance_and_owner_may_reverse_a_payment(): void
    {
        $company = Company::factory()->create();
        $ledger = app(PaymentLedger::class);

        $entry = $ledger->recordManualPayment(
            $company, 500_000, User::factory()->finance()->create()
        );

        $this->expectException(LogicException::class);
        $ledger->reverse($entry, User::factory()->sales()->create(), 'salah input');
    }

    // --- the panel reflects the matrix --------------------------------------

    /**
     * Warehouse staff must not see prices or customer credit data. The
     * resources enforce that, so the enum and the UI cannot drift apart.
     *
     * @return list<array{0: Role, 1: bool, 2: bool, 3: bool}>
     */
    public static function panelAccess(): array
    {
        //            role,             companies, invoices, price imports
        return [
            'sales' => [Role::Sales, true, true, true],
            'warehouse' => [Role::Warehouse, false, false, false],
            'finance' => [Role::Finance, true, true, false],
            'owner' => [Role::Owner, true, true, true],
        ];
    }

    #[DataProvider('panelAccess')]
    public function test_panel_resources_follow_the_matrix(
        Role $role,
        bool $companies,
        bool $invoices,
        bool $priceImports,
    ): void {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($companies, CompanyResource::canViewAny(), 'companies');
        $this->assertSame($invoices, InvoiceResource::canViewAny(), 'invoices');
        $this->assertSame($priceImports, PriceListImportResource::canViewAny(), 'price imports');
    }

    #[DataProvider('matrix')]
    public function test_only_order_taking_roles_can_create_an_order(Role $role, array $expected): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($expected['canCreateOrders'], OrderResource::canCreate());
    }

    /** Nobody edits an invoice through the panel — not even the owner. */
    public function test_invoices_are_never_editable_through_the_panel(): void
    {
        $invoice = Invoice::factory()->create();

        foreach (Role::cases() as $role) {
            $this->actingAs(User::factory()->role($role)->create());

            $this->assertFalse(
                InvoiceResource::canEdit($invoice),
                "{$role->value} must not be able to edit an invoice amount."
            );
            $this->assertFalse(InvoiceResource::canCreate());
        }
    }
}
