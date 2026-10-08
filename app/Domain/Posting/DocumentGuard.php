<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a posted document may change or go: the period of its current date
 * and of its new date must be open, nothing downstream may depend on it, and
 * editing another user's document takes a special right. For every document:
 * dating it before today takes the back-date right, deleting a posted one the
 * delete-posted right, and a user limited to some branches keeps to them.
 */
final class DocumentGuard
{
    /** @var list<Blocker> */
    private array $blockers = [];

    public function __construct(private readonly PeriodLock $periods, private readonly HakAkses $akses) {}

    public function addBlocker(Blocker $blocker): void
    {
        $this->blockers[] = $blocker;
    }

    public function assertMutable(Postable&Model $document, ?CarbonInterface $newDate = null): void
    {
        $this->periods->assertOpen($document->postingDate(), $document->postingNumber());
        if ($newDate !== null) {
            $this->periods->assertOpen($newDate, $document->postingNumber());
        }

        $this->assertOwnOrAllowed($document, $document->postingNumber());

        foreach ($this->blockers as $blocker) {
            $reason = $blocker->blocks($document);
            if ($reason !== null) {
                throw new DocumentLockedException(__(':number cannot be changed: :reason', ['number' => $document->postingNumber(), 'reason' => $reason]));
            }
        }
    }

    /** A document someone else entered is changed (or deleted) only with the "edit other users' transactions" right. */
    /** A document that posts nothing still belongs to its month: a closed month keeps it as it is. */
    public function assertPeriodOpen(?string $date, string $number): void
    {
        if ($date !== null) {
            $this->periods->assertOpen($date, $number);
        }
    }

    public function assertOwnOrAllowed(Model $document, string $number): void
    {
        $creator = $document->getAttribute('created_by');
        $user = auth()->user();
        if ($user !== null && $creator !== null && (int) $creator !== (int) $user->id && ! $this->akses->allowsSpecial($user, HakKhusus::EditOthersTransactions)) {
            throw new DocumentLockedException(__(':number was entered by another user; changing it takes the "edit other users\' transactions" right.', ['number' => $number]));
        }
    }

    /**
     * A document may be dated before today only by a user with the back-date
     * right; an edit that keeps the date it already had is not a back-dating.
     */
    public function assertDateAllowed(?CarbonInterface $old, ?CarbonInterface $new): void
    {
        if ($new === null || $new->toDateString() >= today()->toDateString() || ($old !== null && $old->toDateString() === $new->toDateString())) {
            return;
        }
        $user = auth()->user();
        if ($user !== null && ! $this->akses->allowsSpecial($user, HakKhusus::BackdateTransactions)) {
            throw new DocumentLockedException(__('Dating a transaction before today takes the "back-date transactions" right.'));
        }
    }

    /** A user limited to some branches may only put a document in one of them. */
    public function assertBranchAllowed(?int $branchId): void
    {
        $user = auth()->user();
        if ($user !== null && ! BranchLimit::allows($user, $branchId)) {
            throw new DocumentLockedException(__('You are not assigned to that branch.'));
        }
    }

    /** Deleting a document that has posted takes the delete-posted right. */
    public function assertDeletable(Postable&Model $document, bool $posted): void
    {
        $user = auth()->user();
        if ($posted && $user !== null && ! $this->akses->allowsSpecial($user, HakKhusus::DeletePostedTransactions)) {
            throw new DocumentLockedException(__(':number has been posted; deleting it takes the "delete posted transactions" right.', ['number' => $document->postingNumber()]));
        }
    }

    /** The reason the document is locked, for a disabled button, or null. */
    public function lockReason(Postable&Model $document): ?string
    {
        try {
            $this->assertMutable($document);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
