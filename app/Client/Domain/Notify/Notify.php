<?php

declare(strict_types=1);

namespace App\Client\Domain\Notify;

use App\Client\Access\CentralGroups;
use App\Domain\Shared\Locales;
use App\Models\Sales\Customer;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * One way to tell staff something: a bell notification in the panel for
 * each user, and one mail in the company's language to their addresses.
 * The ops alert keeps its own incident mailbox; everything else (the
 * delivery watch, counts due, birthdays, the month to close) comes here.
 */
final class Notify
{
    /** @return Collection<int, User> */
    public function administrators(): Collection
    {
        return User::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('access_type', 'administrator')->orWhereHas('accessGroups', fn ($g) => $g->where('role_key', CentralGroups::ADMINISTRATOR)))
            ->orderBy('name')->get();
    }

    /** The customer's sales and marketing seats, active. @return Collection<int, User> */
    public function team(Customer $customer): Collection
    {
        return User::query()->where('is_active', true)->whereKey(array_filter([$customer->sales_user_id, $customer->marketing_user_id]))->get();
    }

    /** The active members of a role. @return Collection<int, User> */
    public function role(string $role): Collection
    {
        return User::query()->where('is_active', true)->whereHas('accessGroups', fn ($g) => $g->where('role_key', $role))->orderBy('name')->get();
    }

    /**
     * A bell for each user and, when a mailable is given, one mail to all their addresses.
     *
     * @param  iterable<User>  $users
     * @return list<string> the addresses mailed
     */
    public function send(iterable $users, string $title, ?string $body = null, ?string $url = null, ?Mailable $mail = null): array
    {
        $users = collect($users)->unique('id')->values();
        foreach ($users as $user) {
            $notification = Notification::make()->title($title)->body($body);
            if ($url !== null) {
                $notification->actions([Action::make('open')->label(__('Open'))->url($url)->markAsRead()]);
            }
            $notification->sendToDatabase($user);
        }
        $addresses = $users->pluck('email')->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if ($mail !== null && $addresses !== []) {
            Locales::using(Locales::companyDefault(), fn () => Mail::to($addresses)->send($mail));
        }

        return $addresses;
    }
}
