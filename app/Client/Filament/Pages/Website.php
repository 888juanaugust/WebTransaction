<?php

declare(strict_types=1);

namespace App\Client\Filament\Pages;

use App\Client\Screens\CentralScreen;
use App\Client\Site\Copy;
use App\Client\Site\SiteSettings;
use App\Filament\Support\ErpPage;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * Website: what the public site says about the company, in both languages,
 * overriding the copy written in the client config. Contact details, the
 * about texts, partners, the roadmap and the values the legal pages cite.
 * Every change is audited; a value put back to the config's own is forgotten.
 */
class Website extends ErpPage
{
    protected string $view = 'client.pages.website';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The keys the form edits, each saved as one setting. Lists (a profile's paragraphs, the partners) are one key each. */
    public const KEYS = [
        'short_name', 'tagline', 'summary', 'profile', 'partners', 'roadmap',
        'contact.phone', 'contact.whatsapp', 'contact.email', 'contact.city', 'contact.hours',
        'legal.entity', 'legal.nib', 'legal.established',
        'legal.privacy.effective_since', 'legal.privacy.version', 'legal.privacy.email', 'legal.privacy.correction_hours', 'legal.privacy.breach_notice_hours', 'legal.privacy.server_location',
        'legal.terms.effective_since', 'legal.terms.version', 'legal.terms.late_fee_percent_per_month', 'legal.terms.claim_days', 'legal.terms.dispute_forum',
    ];

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::Website;
    }

    public function mount(): void
    {
        $data = [];
        foreach (self::KEYS as $key) {
            $value = Copy::value($key);
            if ($key === 'profile') {
                $value = ['id' => implode("\n\n", (array) ($value['id'] ?? [])), 'en' => implode("\n\n", (array) ($value['en'] ?? []))];
            }
            Arr::set($data, $key, $value);
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('website')->extraAttributes(['class' => 'ae-tabs-labelled'])->persistTabInQueryString()->tabs([
                    Tab::make('contact')->label(__('Contact'))->icon(Heroicon::OutlinedPhone)->schema([
                        Section::make()->columns(2)->schema([
                            TextInput::make('contact.phone')->label(__('Phone'))->maxLength(40),
                            TextInput::make('contact.whatsapp')->label(__('WhatsApp'))->maxLength(40),
                            TextInput::make('contact.email')->label(__('Email'))->email()->maxLength(150),
                            TextInput::make('contact.city')->label(__('Head office city'))->maxLength(80),
                            ...self::pair('contact.hours', __('Business hours')),
                        ]),
                    ]),
                    Tab::make('about')->label(__('About'))->icon(Heroicon::OutlinedBuildingOffice2)->schema([
                        Section::make()->columns(2)->schema([
                            TextInput::make('short_name')->label(__('Short name'))->maxLength(60)->columnSpanFull()->helperText(__('In the header; the legal name comes from Preferences.')),
                            ...self::pair('tagline', __('Tagline')),
                            ...self::pair('summary', __('Summary'), rows: 3),
                            ...self::pair('profile', __('Profile'), rows: 8, help: __('One paragraph per blank line.')),
                        ]),
                    ]),
                    Tab::make('partners')->label(__('Partners'))->icon(Heroicon::OutlinedUserGroup)->schema([
                        Repeater::make('partners')->label(__('Partners'))->columns(2)->reorderable()->collapsible()
                            ->itemLabel(fn (array $state) => $state['name'] ?? null)
                            ->schema([
                                TextInput::make('name')->label(__('fields.name'))->required()->maxLength(120),
                                TextInput::make('country')->label(__('Country'))->maxLength(60),
                                TextInput::make('since')->label(__('Since'))->maxLength(10),
                                ...self::pair('field', __('Field')),
                                ...self::pair('description', __('fields.description'), rows: 2),
                            ]),
                    ]),
                    Tab::make('roadmap')->label(__('Roadmap'))->icon(Heroicon::OutlinedMap)->schema([
                        Repeater::make('roadmap')->label(__('Roadmap'))->columns(2)->reorderable()->collapsible()
                            ->itemLabel(fn (array $state) => $state['title']['id'] ?? null)
                            ->schema([
                                ...self::pair('title', __('Title')),
                                Select::make('status')->label(__('Status'))->options(['done' => __('Done'), 'ongoing' => __('In progress'), 'planned' => __('Planned')])->required()->native(false),
                                ...self::pair('description', __('fields.description'), rows: 2),
                            ]),
                    ]),
                    Tab::make('legal')->label(__('Legal'))->icon(Heroicon::OutlinedScale)->schema([
                        Section::make(__('Identity'))->columns(3)->schema([
                            TextInput::make('legal.entity')->label(__('Entity'))->maxLength(20),
                            TextInput::make('legal.nib')->label(__('NIB'))->maxLength(30),
                            TextInput::make('legal.established')->label(__('Established'))->maxLength(10),
                        ]),
                        Section::make(__('Privacy policy'))->columns(3)->schema([
                            DatePicker::make('legal.privacy.effective_since')->label(__('Effective since'))->native(false),
                            TextInput::make('legal.privacy.version')->label(__('Version'))->maxLength(10),
                            TextInput::make('legal.privacy.email')->label(__('Privacy email'))->email()->maxLength(150)->helperText(__('Blank: the contact email.')),
                            TextInput::make('legal.privacy.correction_hours')->label(__('Correction within (hours)'))->numeric()->minValue(1),
                            TextInput::make('legal.privacy.breach_notice_hours')->label(__('Breach notice within (hours)'))->numeric()->minValue(1),
                            TextInput::make('legal.privacy.server_location')->label(__('Server location'))->maxLength(80),
                        ]),
                        Section::make(__('Terms of sale'))->columns(3)->schema([
                            DatePicker::make('legal.terms.effective_since')->label(__('Effective since'))->native(false),
                            TextInput::make('legal.terms.version')->label(__('Version'))->maxLength(10),
                            TextInput::make('legal.terms.late_fee_percent_per_month')->label(__('Late fee (% per month)'))->numeric()->minValue(0),
                            TextInput::make('legal.terms.claim_days')->label(__('Claim within (working days)'))->numeric()->minValue(1),
                            TextInput::make('legal.terms.dispute_forum')->label(__('Dispute forum'))->maxLength(120)->columnSpan(2),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data')
            ->disabled(! static::canUpdate());
    }

    public function save(): void
    {
        abort_unless(static::canUpdate(), 403);
        $state = $this->form->getState();
        $values = [];
        foreach (self::KEYS as $key) {
            $value = Arr::get($state, $key);
            if ($key === 'profile') {
                $value = ['id' => self::paragraphs((string) ($value['id'] ?? '')), 'en' => self::paragraphs((string) ($value['en'] ?? ''))];
            }
            $values[$key] = $value;
        }
        app(SiteSettings::class)->setMany($values, auth()->user());
        Notification::make()->title(__('Website saved'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label(__('Save website'))->action('save')->visible(static::canUpdate())];
    }

    /** A bilingual field: one input per language, side by side. @return list<TextInput|Textarea> */
    private static function pair(string $key, string $label, int $rows = 0, ?string $help = null): array
    {
        $fields = [];
        foreach (['id' => 'Bahasa Indonesia', 'en' => 'English'] as $code => $language) {
            $field = $rows > 0 ? Textarea::make("{$key}.{$code}")->rows($rows) : TextInput::make("{$key}.{$code}")->maxLength(300);
            $fields[] = $field->label(sprintf('%s (%s)', $label, $language))->helperText($help);
        }

        return $fields;
    }

    /** @return list<string> */
    private static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $text)) ?: []), fn (string $p) => $p !== ''));
    }
}
