<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Pages;

use App\Domain\Shared\Locales;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** The buyer's own profile: name, phone, email, password, and the language of their screens. */
class EditPortalProfile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(30),
            $this->getEmailFormComponent(),
            Select::make('locale')->label(__('Language'))
                ->options(fn () => Locales::names())
                ->placeholder(fn () => __('As the company: :language', ['language' => Locales::names()[Locales::companyDefault()] ?? Locales::companyDefault()]))
                ->native(false),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function afterSave(): void
    {
        // The new language shows from the next request on.
        $this->redirect(static::getUrl(), navigate: false);
    }
}
