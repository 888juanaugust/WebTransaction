<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Shared\Format;
use App\Filament\Shell\Menu;
use App\Models\Approval\ApprovalRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The bell in the workspace's topbar: the documents waiting for the signed-in
 * user's approval, newest first, each opening as a tab. Only documents the user
 * may approve now and may open are listed. Refreshes every minute while
 * visible. Lazy: the topbar inside a workspace tab is hidden, so it never loads there.
 */
#[Lazy]
final class ApprovalsWaiting extends Component
{
    public const SHOWN = 10;

    public function placeholder(): string
    {
        return '<div class="ae-approvals" aria-hidden="true"></div>';
    }

    public function render(): View
    {
        $user = auth()->user();
        $items = $user instanceof User ? self::itemsFor($user) : [];

        return view('livewire.approvals-waiting', [
            'items' => array_slice($items, 0, self::SHOWN),
            'count' => count($items),
        ]);
    }

    /** @return list<array{title: string, detail: string, url: string}> */
    public static function itemsFor(User $user): array
    {
        $engine = app(ApprovalEngine::class);
        $items = [];
        $requests = ApprovalRequest::query()->where('status', ApprovalRequest::AWAITING)->whereNull('superseded_at')->with('approvable')->latest('id')->get();
        foreach ($requests as $request) {
            $document = $request->approvable;
            $type = $document !== null ? $engine->typeOf($document) : null;
            if ($type === null || ! $engine->canApprove($document, $user)) {
                continue;
            }
            $resource = Filament::getModelResource($document);
            if ($resource === null || ! $resource::hasPage('edit') || ! $resource::canEdit($document)) {
                continue;
            }
            $date = $document->getAttribute('trans_date');
            $items[] = [
                'title' => trim($type->transactionType->getLabel().' '.$document->getAttribute('number')),
                'detail' => collect([$date !== null ? Format::date($date) : null, Format::money(ApprovalEngine::amountOf($document))])->filter()->join(' · '),
                'url' => Menu::path($resource::getUrl('edit', ['record' => $document])),
            ];
        }

        return $items;
    }
}
