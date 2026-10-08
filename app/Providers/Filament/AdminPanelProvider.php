<?php

namespace App\Providers\Filament;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Format;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Workspace;
use App\Filament\Support\InitialsAvatar;
use App\Filament\Support\SafeDelete;
use App\Filament\Support\SideTabIcons;
use App\Filament\Widgets\CompanyPulse;
use App\Http\Controllers\A1SlipController;
use App\Http\Controllers\PrintController;
use App\Http\Middleware\EndInactiveSessions;
use App\Http\Middleware\EnforceAccessWindow;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SetLocale;
use Filament\Actions\DeleteAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\View\View;

/**
 * The one panel. Its look is docs/design/DESIGN.md (tokens in
 * resources/css/filament/admin/theme.css), its colours overridable per client
 * in config/client.php. Its shell is the workspace: an icon rail of the ten
 * module groups (App\Filament\Modul), a tile menu per group, and every
 * screen opened as a live tab (App\Filament\Pages\Workspace). Forms show
 * their tabs as icons down the left side. The brand is the company's name
 * once set in Preferences, else the app name.
 */
class AdminPanelProvider extends PanelProvider
{
    /** The company's name from Preferences, else the app name; never fails, the login page needs it before anything else works. */
    public static function brandName(): string
    {
        $company = rescue(fn () => (string) app(Preferensi::class)->get(PreferensiKey::CompanyName), '', false);

        return trim($company) !== '' ? $company : (string) config('app.name');
    }

    public function boot(): void
    {
        // Form tabs stand down the left side as icons; the status tabs above a list stay on top.
        Tabs::configureUsing(fn (Tabs $tabs) => $tabs->vertical(fn (Tabs $component): bool => blank($component->getLivewireProperty())));
        Tab::configureUsing(fn (Tab $tab) => $tab
            ->icon(fn (Tab $component) => SideTabIcons::for((string) $component->getLabel()))
            ->extraAttributes(fn (Tab $component): array => ['title' => (string) $component->getLabel()], merge: true));

        // A record something else still uses is refused with where it is used, never a database error.
        // (Documents set their own ->using(), through the document repository, which refuses the same way.)
        DeleteAction::configureUsing(fn (DeleteAction $action) => $action->using(fn (Model $record): bool => SafeDelete::run($record)));

        // Every date field types and shows dates in the format Preferences choose.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker->displayFormat(fn (): string => Format::dateInputFormat()));

        // An upload field takes only a file uploaded through it: a path typed into its state (another file on the
        // private disk) is refused, never read, moved, signed for download or deleted. 20 MB at most.
        FileUpload::configureUsing(fn (FileUpload $upload) => $upload->preventFilePathTampering()->maxSize(20 * 1024));

        // DESIGN.md: an empty list names the record. A list a search, filter or tab narrowed says so and how to widen it.
        Table::configureUsing(fn (Table $table) => $table
            ->emptyStateHeading(fn (Table $table): ?string => self::listRecords($table) === null ? null : (self::isNarrowed($table)
                ? __('No :records match', ['records' => self::recordsLabel($table)])
                : __('No :records yet', ['records' => self::recordsLabel($table)])))
            ->emptyStateDescription(fn (Table $table): ?string => self::listRecords($table) !== null && self::isNarrowed($table)
                ? __('Change the search, the filters or the tab to see more.')
                : null)
            ->emptyStateIcon(fn (Table $table): ?string => self::listRecords($table) === null ? null : (self::isNarrowed($table)
                ? 'heroicon-o-magnifying-glass'
                : 'heroicon-o-inbox')));
    }

    /** The list page a table sits on, if it is a resource's list. */
    private static function listRecords(Table $table): ?ListRecords
    {
        $livewire = $table->getLivewire();

        return $livewire instanceof ListRecords ? $livewire : null;
    }

    private static function isNarrowed(Table $table): bool
    {
        $list = self::listRecords($table);

        if ($list === null) {
            return false;
        }

        if (filled($list->getTableSearch()) || array_filter($list->getTableColumnSearches()) !== [] || $table->getActiveFiltersCount() > 0) {
            return true;
        }

        return filled($list->activeTab) && $list->activeTab !== (string) $list->getDefaultActiveTab();
    }

    /** "faktur penjualan", "SPT PPN": lower case mid-sentence, except a word all in capitals. */
    private static function recordsLabel(Table $table): string
    {
        return collect(explode(' ', $table->getPluralModelLabel()))
            ->map(fn (string $word): string => mb_strlen($word) > 1 && mb_strtoupper($word) === $word ? $word : mb_strtolower($word))
            ->implode(' ');
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            ->brandName(fn (): string => self::brandName())
            ->colors(array_merge([
                'primary' => '#2f5bea',
                'gray' => Color::Slate,
                'success' => '#166534',
                'warning' => '#8a5a00',
                'danger' => '#a11d1d',
                'info' => '#2f5bea',
            ], array_filter((array) config('client.theme.colors', []))))
            ->font('Geist Variable', provider: LocalFontProvider::class)
            ->monoFont('Geist Mono Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Light unless the user picks Dark or System in the user menu (DESIGN.md, dark mode).
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->navigation(false)
            ->maxContentWidth(Width::Full)
            ->spa()
            ->databaseTransactions()
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Central's own screens (CLAUDE.md, the client layer).
            ->discoverResources(in: app_path('Client/Filament/Resources'), for: 'App\Client\Filament\Resources')
            ->discoverPages(in: app_path('Client/Filament/Pages'), for: 'App\Client\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                CompanyPulse::class,
                AccountWidget::class,
            ])
            ->authenticatedRoutes(function (): void {
                // Behind sign-in and the access window, like every screen; the print page opens only from a signed link.
                Route::get('/print/{alias}/{id}', PrintController::class)->whereNumber('id')->middleware('signed')->name('print');
                Route::get('/payroll/a1/{employee}/{year}', A1SlipController::class)->whereNumber(['employee', 'year'])->name('a1');
            })
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.shell.head'))
            ->renderHook(
                PanelsRenderHook::LAYOUT_START,
                // A page opened on its own reopens in the workspace, except while the user must first change their
                // password or set up a second factor: then the profile page stands alone (the workspace would only send
                // them back to it).
                fn (array $scopes): View|string => in_array(Workspace::class, $scopes, true) ? view('filament.shell.rail')
                    : (auth()->user()?->profileFirst() !== null ? '' : view('filament.shell.deep-link')),
            )
            // The topbar is its own component and renders hooks without page scopes; the button shows on narrow screens only.
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): View => view('filament.shell.menu-button'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.shell.user-bar'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([EndInactiveSessions::class, SetLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                EnforceAccessWindow::class,
                RequirePasswordChange::class,
            ], isPersistent: true);
    }
}
