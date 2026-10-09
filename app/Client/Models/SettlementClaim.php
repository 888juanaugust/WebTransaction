<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Client\Access\CentralGroups;
use App\Domain\Access\BranchLimit;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A seat's word that the customer paid an invoice, in whole or in part; verified by Finance into a sales receipt. */
class SettlementClaim extends Claim
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(SalesReceipt::class, 'sales_receipt_id');
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
        if (CentralGroups::isMember($user, CentralGroups::SALES) || CentralGroups::isMember($user, CentralGroups::MARKETING)) {
            $query->whereHas('customer', fn (Builder $c) => $c->where('sales_user_id', $user->id)->orWhere('marketing_user_id', $user->id));
        }

        return $query;
    }

    public function auditReference(): string
    {
        return ($this->invoice?->number ?? '#'.$this->getKey()).' · '.($this->customer?->name ?? '');
    }
}
