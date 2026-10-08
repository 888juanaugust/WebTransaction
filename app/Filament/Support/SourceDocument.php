<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuRegistry;
use App\Domain\Approval\ApprovalEngine;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * The upstream document a create page was opened from (?source=…), when the user may see it: in their branches,
 * with the view right on its screen, and approved (nothing is made from a document still waiting). Otherwise none,
 * and the page starts empty.
 */
final class SourceDocument
{
    /** @param  class-string<Model>  $class */
    public static function find(string $class, int $id): ?Model
    {
        if ($id <= 0) {
            return null;
        }

        return self::check(BranchLimit::apply($class::query(), auth()->user())->find($id));
    }

    /** The same checks on a document already found (a receivable or payable picked by its key). */
    public static function check(?Model $source): ?Model
    {
        $user = auth()->user();
        $branch = $source?->getAttribute('branch_id');
        if ($source === null || ! BranchLimit::allows($user, $branch !== null ? (int) $branch : null)) {
            return null;
        }
        $screen = app(MenuRegistry::class)->menuKeyForModel($source::class);
        if ($screen !== null && ! app(HakAkses::class)->allows($user, $screen, Hak::View)) {
            return null;
        }
        if (! app(ApprovalEngine::class)->isApproved($source)) {
            Notification::make()->title(__(':number is not approved; nothing can be made from it yet.', ['number' => $source->getAttribute('number')]))->warning()->send();

            return null;
        }

        return $source;
    }
}
