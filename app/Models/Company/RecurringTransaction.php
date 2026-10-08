<?php

namespace App\Models\Company;

use App\Domain\Audit\RecordsActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A template that becomes a document on its date: a journal, a payment or a receipt that repeats. */
class RecurringTransaction extends Model
{
    use RecordsActivity;

    /** @return array<string, string> document kind → label */
    public static function types(): array
    {
        return ['journal_voucher' => __('Journal voucher'), 'cash_payment' => __('Payment'), 'cash_receipt' => __('Receipt')];
    }

    /** @return array<string, string> frequency → label */
    public static function frequencies(): array
    {
        return ['weekly' => __('Every week'), 'monthly' => __('Every month'), 'yearly' => __('Every year')];
    }

    protected $guarded = [];

    protected function casts(): array
    {
        return ['template' => 'array', 'next_run_on' => 'date', 'last_run_on' => 'date', 'end_on' => 'date', 'run_count' => 'integer', 'run_day' => 'integer'];
    }

    public function scopeDue(Builder $query, \DateTimeInterface|string|null $on = null): Builder
    {
        return $query->where('status', 'active')->where('next_run_on', '<=', CarbonImmutable::parse($on ?? today())->toDateString());
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        // The day a monthly or yearly schedule runs on is the day its next run was set to.
        static::saving(function (self $recurring): void {
            if ($recurring->next_run_on !== null && ($recurring->run_day === null || $recurring->isDirty('next_run_on'))) {
                $recurring->run_day = $recurring->next_run_on->day;
            }
        });
    }

    public function nextAfter(CarbonImmutable $date): CarbonImmutable
    {
        if ($this->frequency === 'weekly') {
            return $date->addWeek();
        }
        $next = $this->frequency === 'yearly' ? $date->addYearNoOverflow() : $date->addMonthNoOverflow();
        $day = $this->run_day ?: $date->day;

        return $next->setDay(min($day, $next->daysInMonth)); // the 31st is month-end in every month
    }
}
