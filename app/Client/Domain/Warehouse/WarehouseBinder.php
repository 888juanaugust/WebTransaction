<?php

declare(strict_types=1);

namespace App\Client\Domain\Warehouse;

use App\Client\Access\CentralGroups;
use App\Domain\Access\HakAkses;
use App\Domain\Audit\Auditor;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One warehouse, one account: a Warehouse (gudang) user is bound to exactly
 * one warehouse, and a warehouse holds at most one active bound account.
 * Binding puts the user in the warehouse's branch; a hand-over deactivates
 * the old account first. Only an administrator binds, and every change is
 * audited.
 */
final class WarehouseBinder
{
    public function __construct(private readonly HakAkses $access) {}

    public function bind(Warehouse $warehouse, User $user, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator binds a warehouse account.'));
        }
        if (! $user->is_active) {
            throw new RuntimeException(__(':name is not an active user.', ['name' => $user->name]));
        }
        if (! CentralGroups::isMember($user, CentralGroups::WAREHOUSE)) {
            throw new RuntimeException(__(':name is not a member of the Warehouse group.', ['name' => $user->name]));
        }
        $this->assertFree($warehouse, $user);

        DB::transaction(function () use ($warehouse, $user): void {
            $before = $this->boundIds($user);
            DB::table('warehouse_users')->where('user_id', $user->id)->delete();
            DB::table('warehouse_users')->insert(['warehouse_id' => $warehouse->id, 'user_id' => $user->id]);
            if ($warehouse->branch_id !== null) {
                $user->branches()->sync([$warehouse->branch_id]);
            }
            $this->access->forget($user);
            Auditor::log('warehouse_bound', $user, $user->name, ['before' => $before, 'after' => [$warehouse->id], 'warehouse' => $warehouse->name]);
        });
    }

    public function unbind(User $user, User $actor): void
    {
        if (! $actor->isAdministrator()) {
            throw new RuntimeException(__('Only an administrator binds a warehouse account.'));
        }
        $before = $this->boundIds($user);
        if ($before === []) {
            return;
        }
        DB::table('warehouse_users')->where('user_id', $user->id)->delete();
        $this->access->forget($user);
        Auditor::log('warehouse_unbound', $user, $user->name, ['before' => $before, 'after' => []]);
    }

    /** The active Warehouse-group account bound to this warehouse, if any (other than $except). */
    public function holder(Warehouse $warehouse, ?User $except = null): ?User
    {
        return User::query()->where('is_active', true)
            ->whereHas('accessGroups', fn ($q) => $q->where('role_key', CentralGroups::WAREHOUSE))
            ->whereIn('id', DB::table('warehouse_users')->where('warehouse_id', $warehouse->id)->select('user_id'))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('id')->first();
    }

    /** Refuses when another active Warehouse account already holds the warehouse. */
    public function assertFree(Warehouse $warehouse, ?User $except = null): void
    {
        $holder = $this->holder($warehouse, $except);
        if ($holder !== null) {
            throw new RuntimeException(__(':warehouse is held by :name: one warehouse, one account. Deactivate the old account first.', ['warehouse' => $warehouse->name, 'name' => $holder->name]));
        }
    }

    /** @return list<int> */
    private function boundIds(User $user): array
    {
        return DB::table('warehouse_users')->where('user_id', $user->id)->orderBy('warehouse_id')->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
    }
}
