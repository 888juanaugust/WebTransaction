<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Tax\TaxFiling;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The Art. 21 screens' earlier exports, each downloadable again. */
trait ShowsPph21Filings
{
    abstract protected function filingKind(): string;

    protected function filingsAction(): Action
    {
        return Action::make('filings')
            ->label(__('Previous exports'))
            ->icon('heroicon-m-folder')
            ->color('gray')
            ->modalHeading(__('Previous exports'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(fn () => view('filament.pages.reports.pph21-filings', [
                'filings' => TaxFiling::query()->where('kind', $this->filingKind())->latest('id')->limit(24)->get(),
            ]));
    }

    public function downloadFiling(int $id): ?BinaryFileResponse
    {
        $filing = TaxFiling::query()->where('kind', $this->filingKind())->find($id);
        if ($filing === null || ! Storage::disk('local')->exists($filing->file_path)) {
            return null;
        }

        return response()->download(Storage::disk('local')->path($filing->file_path), $filing->file_name);
    }
}
