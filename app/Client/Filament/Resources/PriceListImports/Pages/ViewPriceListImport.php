<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\PriceListImports\Pages;

use App\Client\Domain\PriceList\PriceListExporter;
use App\Client\Domain\PriceList\PriceListPublisher;
use App\Client\Filament\Resources\PriceListImports\PriceListImportResource;
use App\Client\Jobs\ParsePriceListImport;
use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListImportRow;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

/** One upload under review: the diff, the biggest moves, the blocked and annotated rows, and the decision to publish or discard. */
class ViewPriceListImport extends ViewRecord
{
    protected static string $resource = PriceListImportResource::class;

    protected string $view = 'client.resources.price-list-imports.view';

    public function getTitle(): string
    {
        return (string) $this->record->original_filename;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish as the next version'))
                ->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => $this->record->status === PriceListImport::PARSED && self::mayDecide())
                ->modalHeading(__('Publish as the next version'))
                ->modalDescription(fn () => $this->record->brakeTripped()
                    ? __('Large changes: :reasons', ['reasons' => implode(' ', (array) ($this->record->diff['brake_reasons'] ?? []))])
                    : __('Every row without a blocker becomes the next version; items the file does not name :carry.', ['carry' => $this->record->is_full_replacement ? __('are switched off') : __('keep their price')]))
                ->schema([
                    DatePicker::make('effective_from')->label(__('Effective from'))->native(false)->required()->default(fn () => $this->record->effective_from ?? today()),
                    Textarea::make('acknowledgement')->label(__('Second confirmation'))->rows(2)->required()
                        ->helperText(__('Write what you checked and with whom; the numbers above are recorded with it.'))
                        ->visible(fn () => $this->record->brakeTripped()),
                ])
                ->action(function (array $data): void {
                    try {
                        $version = app(PriceListPublisher::class)->publish($this->record, auth()->user(), $data['effective_from'], $data['acknowledgement'] ?? null);
                        Notification::make()->title(__('Price list version #:id published', ['id' => $version->id]))->success()->send();
                        $this->redirect(PriceListImportResource::getUrl('index'));
                    } catch (DomainException $e) {
                        Notification::make()->title(__('Cannot publish'))->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('reparse')
                ->label(__('Process again'))
                ->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn () => in_array($this->record->status, [PriceListImport::FAILED, PriceListImport::PARSED, PriceListImport::UPLOADED], true) && self::mayDecide())
                ->action(function (): void {
                    ParsePriceListImport::dispatch($this->record->id)->afterCommit();
                    Notification::make()->title(__('Processing again'))->success()->send();
                }),
            Action::make('discard')
                ->label(__('Discard'))
                ->icon('heroicon-m-x-circle')->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, [PriceListImport::UPLOADED, PriceListImport::PARSED, PriceListImport::FAILED], true) && self::mayDecide())
                ->action(function (): void {
                    try {
                        app(PriceListPublisher::class)->discard($this->record, auth()->user());
                        Notification::make()->title(__('Upload discarded'))->warning()->send();
                        $this->redirect(PriceListImportResource::getUrl('index'));
                    } catch (DomainException $e) {
                        Notification::make()->title(__('Cannot discard'))->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('exportVersion')
                ->label(fn () => __('Export version #:id', ['id' => $this->record->version_id]))
                ->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn () => $this->record->version !== null)
                ->action(fn () => app(PriceListExporter::class)->download($this->record->version)),
        ];
    }

    public static function mayDecide(): bool
    {
        return app(HakAkses::class)->allows(auth()->user(), CentralScreen::PriceList, Hak::Update);
    }

    /** @return Collection<int, PriceListImportRow> */
    public function blockers(): Collection
    {
        return $this->record->rows()->where('status', PriceListImportRow::BLOCKER)->orderBy('id')->limit(200)->get();
    }

    /** @return Collection<int, PriceListImportRow> */
    public function notes(): Collection
    {
        return $this->record->rows()->where('status', PriceListImportRow::NOTE)->orderBy('id')->limit(200)->get();
    }
}
