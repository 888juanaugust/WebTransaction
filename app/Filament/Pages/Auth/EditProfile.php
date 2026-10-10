<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Shared\Locales;
use App\Filament\Pages\Workspace;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** The user's own profile: name, email, password, and the language of their screens. */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(__('Administrators sign in with a second factor: set up an authenticator app below before anything else.'))
                ->color('danger')->visible(fn (): bool => $this->getUser()->profileFirst() === 'two_factor'),
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Select::make('locale')->label(__('Language'))
                ->options(fn () => Locales::names())
                ->placeholder(fn () => __('As the company: :language', ['language' => Locales::names()[Locales::companyDefault()] ?? Locales::companyDefault()]))
                ->native(false),
            $this->getPasswordFormComponent()
                ->required(fn (): bool => (bool) $this->getUser()->getAttribute('password_change_required'))
                ->helperText(fn (): ?string => $this->getUser()->getAttribute('password_change_required') ? __('Choose your own password before anything else.') : null),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /**
     * The current password confirms a change, except at first sign-in: the password someone else chose is
     * being replaced, and the person just typed it to get here.
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        $firstSetup = fn (): bool => (bool) $this->getUser()->getAttribute('password_change_required');

        return parent::getCurrentPasswordFormComponent()
            ->required(fn (): bool => ! $firstSetup())
            ->visible(fn (Get $get): bool => ! $firstSetup()
                && (filled($get('password')) || $get('email') !== $this->getUser()->getAttributeValue('email')));
    }

    protected function afterSave(): void
    {
        $user = $this->getUser();
        if ($user->wasChanged('password') && $user->getAttribute('password_change_required')) {
            $user->forceFill(['password_change_required' => false])->save();
            $this->redirect(Workspace::getUrl(), navigate: false); // the screens are open now

            return;
        }
        // The new language shows from the next request on.
        $this->redirect(static::getUrl(), navigate: false);
    }
}
