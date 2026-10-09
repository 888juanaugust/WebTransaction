<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Client\Access\CentralGroups;
use App\Domain\Access\BranchLimit;
use App\Models\CashBank\CashPayment;
use App\Models\Sales\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a sales user spent — on the road, or for one of their customers — awaiting Finance's key to become a cash payment. */
class ExpenseClaim extends Claim
{
    protected function casts(): array
    {
        return parent::casts() + ['trans_date' => 'date'];
    }

    public function salesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class, 'cash_payment_id');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdministrator()) {
            return $query;
        }
        BranchLimit::apply($query, $user);
        if (CentralGroups::isMember($user, CentralGroups::SALES)) {
            $query->where('sales_user_id', $user->id);
        }

        return $query;
    }

    public function auditReference(): string
    {
        return ($this->salesUser?->name ?? '#'.$this->getKey()).' · '.$this->trans_date?->toDateString();
    }
}
