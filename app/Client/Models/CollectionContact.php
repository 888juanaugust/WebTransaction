<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One contact about an unpaid invoice: how, when, what came of it, and the promise if there was one. */
class CollectionContact extends Model implements HasAuditReference
{
    use RecordsActivity;

    public const METHODS = ['phone', 'whatsapp', 'visit', 'email'];

    public const OUTCOMES = ['promise', 'asks_time', 'unreachable', 'dispute', 'paid'];

    public const PROMISE = 'promise';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['promise_date' => 'date', 'promise_amount' => 'integer', 'contacted_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPromise(): bool
    {
        return $this->outcome === self::PROMISE;
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'phone' => __('Phone call'),
            'whatsapp' => __('WhatsApp'),
            'visit' => __('Visit'),
            'email' => __('Email'),
            default => $method,
        };
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'promise' => __('Promised to pay'),
            'asks_time' => __('Asks for more time'),
            'unreachable' => __('Could not be reached'),
            'dispute' => __('Disputes the invoice'),
            'paid' => __('Says it is paid'),
            default => $outcome,
        };
    }

    public function auditReference(): string
    {
        return ($this->invoice?->number ?? '#'.$this->getKey()).' · '.($this->customer?->name ?? '');
    }
}
