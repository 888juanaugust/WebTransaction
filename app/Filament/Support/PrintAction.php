<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Printing\Printable;
use App\Domain\Printing\PrintJob;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/** The "Print" button on a document list: opens the printable page in a new tab, when the screen's print right allows and the document is approved. */
final class PrintAction
{
    public static function make(): Action
    {
        return Action::make('print')
            ->label(__('Print'))
            ->icon('heroicon-m-printer')
            ->color('gray')
            ->url(fn (Model $record): ?string => PrintJob::url($record), shouldOpenInNewTab: true)
            ->visible(fn (Model $record): bool => Printable::aliasOf($record) !== null && self::allowed() && app(ApprovalEngine::class)->isApproved($record));
    }

    private static function allowed(): bool
    {
        $page = Livewire::current();
        $resource = $page && method_exists($page, 'getResource') ? $page::getResource() : null;

        return $resource === null || ! method_exists($resource, 'canPrint') || $resource::canPrint();
    }
}
