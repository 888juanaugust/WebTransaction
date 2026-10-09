<?php

declare(strict_types=1);

namespace App\Client\Domain\Teams;

use App\Client\Access\CentralGroups;
use App\Domain\Access\BranchLimit;
use App\Domain\Audit\Auditor;
use App\Models\Company\Branch;
use App\Models\Sales\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The team in charge of a customer: one sales and one marketing seat. Only
 * an administrator assigns them. The sales must be able to work in the
 * customer's branch; the marketing is global and must see every branch.
 * Every change is audited with both seats before and after.
 */
final class TeamAssigner
{
    public function assign(Customer $customer, ?User $sales, ?User $marketing, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator assigns a customer\'s team.'));
        }
        if ($sales !== null) {
            $this->assertActive($sales);
            if (! CentralGroups::isMember($sales, CentralGroups::SALES)) {
                throw new RuntimeException(__(':name is not in the Sales group, so cannot hold the sales seat.', ['name' => $sales->name]));
            }
            if (! BranchLimit::allows($sales, $customer->branch_id)) {
                throw new RuntimeException(__(':name cannot work in :branch, the customer\'s branch.', ['name' => $sales->name, 'branch' => $customer->branch?->name ?? '—']));
            }
        }
        if ($marketing !== null) {
            $this->assertActive($marketing);
            if (! CentralGroups::isMember($marketing, CentralGroups::MARKETING)) {
                throw new RuntimeException(__(':name is not in the Marketing group, so cannot hold the marketing seat.', ['name' => $marketing->name]));
            }
            if (Branch::limitsOf($marketing) !== null) {
                throw new RuntimeException(__(':name must see every branch to hold a marketing seat.', ['name' => $marketing->name]));
            }
        }

        DB::transaction(function () use ($customer, $sales, $marketing): void {
            $before = ['sales_user_id' => $customer->sales_user_id, 'marketing_user_id' => $customer->marketing_user_id];
            $after = ['sales_user_id' => $sales?->id, 'marketing_user_id' => $marketing?->id];
            if ($before === $after) {
                return;
            }
            $customer->forceFill($after)->saveQuietly();
            Auditor::log('team_assigned', $customer, $customer->number, ['before' => $before, 'after' => $after]);
        });
    }

    /** Whether the user may approve this customer's orders: the owner, or the customer's marketing seat. */
    public static function holdsApprovalSeat(?User $user, Customer $customer): bool
    {
        return $user !== null && $user->is_active && ($user->isAdministrator() || ($customer->marketing_user_id !== null && (int) $customer->marketing_user_id === $user->id));
    }

    private function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw new RuntimeException(__(':name is not an active user.', ['name' => $user->name]));
        }
    }
}
