<?php

declare(strict_types=1);

namespace App\Domain\Printing;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuRegistry;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\Auditor;
use App\Domain\Company\CompanyIdentity;
use App\Domain\Pengaturan\Preferensi;
use App\Models\Company\PrintLayout;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * One print of one document: the layout chosen for its type (the user's
 * own, else the default), the company header from the preferences, the
 * right checked, the document marked printed and the print logged.
 */
final class PrintJob
{
    public function __construct(private readonly HakAkses $akses, private readonly MenuRegistry $menus, private readonly Preferensi $prefs) {}

    /** @return array{document: Model, meta: array, layout: array, company: array, title: string} */
    public function prepare(string $alias, int $id, User $user, ?int $layoutId = null): array
    {
        $print = $this->data($alias, $id, $user, $layoutId);
        $document = $print['document'];
        if (! $document->getAttribute('is_printed') && $document->getConnection()->getSchemaBuilder()->hasColumn($document->getTable(), 'is_printed')) {
            $document->forceFill(['is_printed' => true])->saveQuietly();
        }
        Auditor::log('printed', $document, (string) $document->getAttribute('number'), ['layout' => $print['layout']['name'] ?? 'Standard']);

        return $print;
    }

    /**
     * What a print of the document shows, with the right checked, but the document not marked printed (a PDF
     * attached to an email, say).
     *
     * @return array{document: Model, meta: array, layout: array, company: array, title: string}
     */
    public function data(string $alias, int $id, User $user, ?int $layoutId = null): array
    {
        $meta = Printable::for($alias) ?? throw new RuntimeException(__('Nothing called :alias prints.', ['alias' => $alias]));
        // Only what the user's branches let them see, on a screen whose module is on, with view and print rights there.
        $document = BranchLimit::apply($meta['model']::query(), $user)->findOrFail($id);
        $menu = $this->menus->menuKeyForModel($document::class);
        if ($menu !== null && (! app(ModuleRegistry::class)->menuKeyEnabled($menu)
            || ! $this->akses->allows($user, $menu, Hak::View) || ! $this->akses->allows($user, $menu, Hak::Print))) {
            throw new RuntimeException(__('Printing this document takes the print right on its screen.'));
        }
        app(ApprovalEngine::class)->assertApproved($document, __('is not approved; it cannot be printed yet.'));
        $layout = $this->layoutFor($meta['type']->value, $user, $layoutId);

        return [
            'document' => $document,
            'meta' => $meta,
            'layout' => $layout,
            'company' => app(CompanyIdentity::class)->letterhead(),
            'title' => (string) (($layout['title'] ?? null) ?: $meta['title']),
        ];
    }

    /**
     * The printable page's address: signed and short-lived, so only a Print button of this application opens it
     * (opening it marks the document printed, which a page elsewhere must not be able to do).
     */
    public static function url(Model $document, ?int $layoutId = null): ?string
    {
        $alias = Printable::aliasOf($document);

        return $alias === null ? null : URL::temporarySignedRoute('filament.admin.print', now()->addMinutes(30), array_filter(['alias' => $alias, 'id' => $document->getKey(), 'layout' => $layoutId]));
    }

    /** @return array<string, mixed> the layout's settings plus its name */
    public function layoutFor(string $transactionType, User $user, ?int $layoutId = null): array
    {
        $query = PrintLayout::query()->where('transaction_type', $transactionType)
            ->where(fn ($q) => $q->where('used_all_user', true)->orWhereHas('users', fn ($u) => $u->whereKey($user->id)));
        $layout = $layoutId ? (clone $query)->find($layoutId) : null;
        $layout ??= (clone $query)->where('is_default', true)->first() ?? $query->orderBy('id')->first();

        return ($layout?->settings() ?? PrintLayout::DEFAULTS) + ['name' => $layout?->name ?? 'Standard', 'id' => $layout?->id];
    }
}
