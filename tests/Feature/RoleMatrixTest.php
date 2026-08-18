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
                // Sales quote the customer's price; what we paid is not their business.
                'canSeeCost' => false,
                'canRecordPurchases' => false,
                'canPostJournals' => false,
                'canSeeBooks' => false,
                'canClosePeriod' => false,
                'canReopenPeriod' => false,
                'canCreateOrders' => true,
                'canConfirmPayment' => false,
                'canEditOrderPrices' => true,
                // A credit note reduces what a customer owes, which is editing
                // the invoice amount by another name — so it follows price
                // authority, not payment authority.
                'canIssueCreditNote' => true,
                'canOverrideCreditLimit' => false,
                'canPickAndShip' => false,
                'canTransferStock' => false,
                'canCountStock' => false,
                'canApproveStockCount' => false,
                'canAllocateLandedCost' => false,
                // Sending goods back is the receipt read backwards: it carries
                // what we paid on every line, which Sales never see.
                'canReturnToSupplier' => false,
                // They are handed the cheque at the counter and still do not
                // decide its fate: recording a bounce moves what a customer
                // owes, which is the one thing this role must not do to money.
                'canHandleGiro' => false,
                // They issue the invoices behind it, but filing is a
                // statement to the tax office about what was sold, and the
                // people paid on what was sold should not be making it.
                'canExportFaktur' => false,
                'canSeeReports' => true,
                'canViewAuditLog' => false,
            ]],
            'warehouse' => [Role::Warehouse, [
                'canSeePrices' => false,
                'canSeeCreditData' => false,
                'canSeeCost' => false,
                'canRecordPurchases' => false,
                'canPostJournals' => false,
                'canSeeBooks' => false,
                'canClosePeriod' => false,
                'canReopenPeriod' => false,
                'canCreateOrders' => false,
                'canConfirmPayment' => false,
                'canEditOrderPrices' => false,
                'canIssueCreditNote' => false,
                'canOverrideCreditLimit' => false,
                'canPickAndShip' => true,
                // Counts the shelf; approving what they found is somebody
                // else's, or a count is a way to make stock disappear.
                'canTransferStock' => true,
                'canCountStock' => true,
                'canApproveStockCount' => false,
                'canAllocateLandedCost' => false,
                // They hand the cartons back to the driver and enter nothing —
                // the same compromise canRecordPurchases already names.
                'canReturnToSupplier' => false,
                'canHandleGiro' => false,
                'canExportFaktur' => false,
                // Every report is money, and this role never sees money.
                'canSeeReports' => false,
                'canViewAuditLog' => false,
            ]],
            'finance' => [Role::Finance, [
                'canSeePrices' => true,
                'canSeeCreditData' => true,
                'canSeeCost' => true,
                'canRecordPurchases' => true,
                'canPostJournals' => true,
                'canSeeBooks' => true,
                // Finance closes the month; only the Owner can undo it.
                'canClosePeriod' => true,
                'canReopenPeriod' => false,
                'canCreateOrders' => false,
                'canConfirmPayment' => true,
                'canEditOrderPrices' => false,
                // Finance confirm payments, so they must not be able to write
                // a receivable off as a return nobody witnessed.
                'canIssueCreditNote' => false,
                'canOverrideCreditLimit' => true,
                'canPickAndShip' => false,
                'canTransferStock' => false,
                'canCountStock' => false,
                'canApproveStockCount' => true,
                // Bookkeeping judgement about a supplier invoice, so it
                // follows purchase authority rather than the opname split —
                // an allocation creates no payable and no stock.
                'canAllocateLandedCost' => true,
                // Not split into a raise/approve pair like the stock count,
                // because a return has a counterparty who has to agree with it.
                'canReturnToSupplier' => true,
                // A giro clearing is a payment and goes through the same
                // ledger, so it follows payment authority.
                'canHandleGiro' => true,
                'canExportFaktur' => true,
                'canSeeReports' => true,
                'canViewAuditLog' => false,
            ]],
            'owner' => [Role::Owner, [
                'canSeePrices' => true,
                'canSeeCreditData' => true,
                'canSeeCost' => true,
                'canRecordPurchases' => true,
                'canPostJournals' => true,
                'canSeeBooks' => true,
                'canClosePeriod' => true,
                'canReopenPeriod' => true,
                'canCreateOrders' => true,
                'canConfirmPayment' => true,
                'canEditOrderPrices' => true,
                'canIssueCreditNote' => true,
                'canOverrideCreditLimit' => true,
                'canPickAndShip' => true,
                'canTransferStock' => true,
                'canCountStock' => true,
                'canApproveStockCount' => true,
                'canAllocateLandedCost' => true,
                'canReturnToSupplier' => true,
                'canHandleGiro' => true,
                'canExportFaktur' => true,
                'canSeeReports' => true,
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

    /**
     * Warehouse must not reach a resource nobody deliberately opened to them.
     *
     * The tuple list above names three resources, which means a resource added
     * later is simply not covered — and "somebody forgot to gate the new
     * screen" is the way the warehouse rule actually breaks, not a change to
     * one of the three that were already thought about.
     *
     * So this walks every resource in the panel and requires each one to be on
     * an explicit allowlist. A new resource is denied by default here, and
     * opening it to the warehouse means saying so in this test.
     */
    public function test_warehouse_reaches_no_admin_resource_that_was_not_deliberately_opened(): void
    {
        $allowed = [
            // Pick lists and shipping: the warehouse's own work. The resource
            // hides every money column for them.
            'OrderResource',
            // The catalogue, so a packer can look a part number up. Prices are
            // hidden by canSeePrices().
            'ProductResource',
            /*
             * Moving stock between our own warehouses. Warehouse work by
             * definition — they are the ones carrying the cartons — and the
             * value column is hidden from them by canSeeCost(). A transfer
             * cannot change what the inventory is worth anyway, so there is
             * nothing on this screen they could give away.
             */
            'StockTransferResource',
            /*
             * Counting the shelf. They fill the sheet in and cannot approve
             * what they found: canApproveStockCount() excludes them, so the
             * variance is signed off by finance or the owner. The rupiah
             * column is hidden from them; the quantity is not, because the
             * quantity is what they counted.
             */
            'StockOpnameResource',
        ];

        $this->actingAs(User::factory()->role(Role::Warehouse)->create());

        $reachable = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            $class = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');

            if (class_exists($class) && $class::canViewAny()) {
                $reachable[] = class_basename($class);
            }
        }

        sort($reachable);
        sort($allowed);

        $this->assertSame($allowed, $reachable, sprintf(
            "Warehouse can reach: %s.\nAnything not on the allowlist in this test is a screen that "
            .'was added without deciding whether the warehouse may see it. Warehouse must never see '
            .'prices, costs, or customer credit data.',
            implode(', ', $reachable),
        ));
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
