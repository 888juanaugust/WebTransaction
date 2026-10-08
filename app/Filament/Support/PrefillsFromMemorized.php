<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Company\MemorizedTransaction;

/**
 * A create page opened with ?memorized=ID starts from that memorized
 * transaction: its header fields filled, its lines in the grid, the date and
 * number left to the page's own defaults.
 */
trait PrefillsFromMemorized
{
    /** The transaction_type a memorized transaction must carry to fill this page. */
    abstract protected static function memorizedType(): string;

    protected function prefillFromMemorized(): void
    {
        $id = request()->integer('memorized');
        if ($id <= 0) {
            return;
        }
        $memorized = MasterResource::visibleToCurrentUser(MemorizedTransaction::query())->find($id);
        if ($memorized === null || $memorized->transaction_type !== static::memorizedType()) {
            return;
        }

        $template = $memorized->template ?? [];
        $lines = $template['lines'] ?? [];
        unset($template['lines']);

        $this->form->fill(array_merge($this->form->getRawState(), $template));
        $this->data['lines'] = DocumentPages::keyedRows(array_values($lines));
    }
}
