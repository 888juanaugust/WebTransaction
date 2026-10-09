<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\SiteImages;

use App\Client\Filament\Resources\SiteImages\Pages\ManageSiteImages;
use App\Client\Models\SiteImage;
use App\Client\Screens\CentralScreen;
use App\Filament\Support\MasterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Website Images: the promo slides and the photos the public home page
 * shows. Any picture the Owner wants seen: the goods, the warehouse, a
 * campaign. Shown while active and within its dates; the file lives on the
 * public disk.
 */
class SiteImageResource extends MasterResource
{
    protected static ?string $model = SiteImage::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'Website image';

    protected static ?string $recordTitleAttribute = 'image_path';

    public static function menuKey(): CentralScreen
    {
        return CentralScreen::SiteImages;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('kind')->label(__('Kind'))->options(self::kinds())->required()->native(false)->live()
                    ->helperText(__('A promo is a slide of the carousel with a title, a text and a link; a photo goes in the gallery with its caption.')),
                FileUpload::make('image_path')->label(__('Image'))->image()->disk('public')->directory('promo')->visibility('public')
                    ->maxSize(2048)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->required()->imageEditor()->columnSpanFull(),
                TextInput::make('title.id')->label(__('Title (Bahasa Indonesia)'))->required()->maxLength(120),
                TextInput::make('title.en')->label(__('Title (English)'))->required()->maxLength(120),
                Textarea::make('text.id')->label(__('Text (Bahasa Indonesia)'))->rows(2)->maxLength(300)->visible(fn (Get $get) => $get('kind') === SiteImage::PROMO),
                Textarea::make('text.en')->label(__('Text (English)'))->rows(2)->maxLength(300)->visible(fn (Get $get) => $get('kind') === SiteImage::PROMO),
                TextInput::make('link')->label(__('Link'))->maxLength(255)->visible(fn (Get $get) => $get('kind') === SiteImage::PROMO)
                    ->helperText(__('Where the slide leads; a path on this site or a full address.')),
                TextInput::make('sort')->label(__('Order'))->numeric()->default(0)->minValue(0),
                DatePicker::make('show_from')->label(__('Show from'))->native(false),
                DatePicker::make('show_until')->label(__('Show until'))->native(false)->afterOrEqual('show_from'),
                Toggle::make('is_active')->label(__('Active'))->default(true)->inline(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('image_path')->label(__('Image'))->disk('public')->height(48),
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (string $state) => self::kinds()[$state] ?? $state)
                    ->color(fn (string $state) => $state === SiteImage::PROMO ? 'info' : 'gray'),
                TextColumn::make('title.id')->label(__('Title'))->searchable()->weight('medium')->description(fn (SiteImage $r) => $r->title['en'] ?? null),
                TextColumn::make('show_from')->label(__('Show from'))->date()->placeholder('—'),
                TextColumn::make('show_until')->label(__('Show until'))->date()->placeholder('—'),
                TextColumn::make('sort')->label(__('Order'))->sortable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Kind'))->options(self::kinds()),
            ])
            ->recordActions([
                EditAction::make()->label(__('Edit'))->slideOver(),
                DeleteAction::make()->label(__('Delete')),
            ])
            ->emptyStateHeading(__('No website images yet'));
    }

    /** @return array<string, string> */
    private static function kinds(): array
    {
        return [SiteImage::PROMO => __('Promo'), SiteImage::PHOTO => __('Photo')];
    }

    public static function getPages(): array
    {
        return ['index' => ManageSiteImages::route('/')];
    }
}
