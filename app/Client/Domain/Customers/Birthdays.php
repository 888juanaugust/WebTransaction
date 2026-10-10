<?php

declare(strict_types=1);

namespace App\Client\Domain\Customers;

use App\Client\Domain\Notify\Notify;
use App\Client\Mail\ReminderMessage;
use App\Domain\Shared\Format;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Models\Sales\CustomerContact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The birthdays of the people at a customer. Three days before and on the
 * day, the customer's team and the administrators are told by bell and
 * mail, once per person per year; the customer itself is never written to.
 * A birth date is personal data: it leaves with the customer's export and
 * goes nowhere else.
 */
final class Birthdays
{
    public const DAYS_BEFORE = 3;

    public function __construct(private readonly Notify $notify) {}

    /** The contacts whose birthday falls on a day (any year), with their customer. @return Collection<int, CustomerContact> */
    public function on(CarbonImmutable $day): Collection
    {
        return CustomerContact::query()->with('customer')
            ->whereNotNull('birth_date')
            ->whereRaw('EXTRACT(MONTH FROM birth_date) = ? AND EXTRACT(DAY FROM birth_date) = ?', [$day->month, $day->day])
            ->whereHas('customer', fn (Builder $q) => $q->where('is_active', true))
            ->get();
    }

    /** Sends today's reminders: the people whose birthday is in three days, and today's; returns how many were told. */
    public function remind(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;
        foreach ([['day' => $today->addDays(self::DAYS_BEFORE), 'kind' => 'before'], ['day' => $today, 'kind' => 'day']] as $when) {
            foreach ($this->on($when['day']) as $contact) {
                $inserted = DB::table('birthday_notices')->insertOrIgnore(['customer_contact_id' => $contact->id, 'year' => $when['day']->year, 'kind' => $when['kind'], 'sent_at' => now()]);
                if ($inserted === 0) {
                    continue;
                }
                $customer = $contact->customer;
                $title = $when['kind'] === 'day'
                    ? __('Today is :name\'s birthday (:customer)', ['name' => $contact->name, 'customer' => $customer->name])
                    : __(':name\'s birthday is on :date (:customer)', ['name' => $contact->name, 'date' => Format::date($when['day']), 'customer' => $customer->name]);
                $body = collect([$contact->position, $contact->mobile_phone])->filter()->join(' · ') ?: null;
                $recipients = $this->notify->team($customer)->concat($this->notify->administrators());
                $addresses = $this->notify->send($recipients, $title, $body, CustomerResource::getUrl('edit', ['record' => $customer]), new ReminderMessage($title, array_filter([$body])));
                DB::table('birthday_notices')->where('customer_contact_id', $contact->id)->where('year', $when['day']->year)->where('kind', $when['kind'])->update(['sent_to' => json_encode($addresses)]);
                $sent++;
            }
        }

        return $sent;
    }
}
