<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Support\Icons\Heroicon;

/**
 * The icon of every form tab, shown in the narrow column of side tabs. Keyed
 * by the tab's untranslated name, matched against the translated label, so
 * the icons follow the tabs in every language.
 */
final class SideTabIcons
{
    /** @var array<string, Heroicon> */
    public const ICONS = [
        'fields.lines' => Heroicon::OutlinedListBullet,
        'Journal lines' => Heroicon::OutlinedListBullet,
        'Expense lines' => Heroicon::OutlinedListBullet,
        'Budget lines' => Heroicon::OutlinedListBullet,
        'Components' => Heroicon::OutlinedRectangleStack,
        'Units' => Heroicon::OutlinedScale,
        'Invoices' => Heroicon::OutlinedDocumentText,
        'Receipt details' => Heroicon::OutlinedListBullet,
        'Payment details' => Heroicon::OutlinedListBullet,
        'fields.other_info' => Heroicon::OutlinedInformationCircle,
        'Other info' => Heroicon::OutlinedInformationCircle,
        'General' => Heroicon::OutlinedInformationCircle,
        'Asset details' => Heroicon::OutlinedInformationCircle,
        'fields.other_charges' => Heroicon::OutlinedBanknotes,
        'Expenditure' => Heroicon::OutlinedBanknotes,
        'Transfer fees' => Heroicon::OutlinedArrowsRightLeft,
        'Other' => Heroicon::OutlinedEllipsisHorizontalCircle,
        'Notes' => Heroicon::OutlinedPencilSquare,
        'Progress' => Heroicon::OutlinedChartBar,
        'Fiscal' => Heroicon::OutlinedScale,
        'Opening balance' => Heroicon::OutlinedCalendarDays,
        'Tax info' => Heroicon::OutlinedReceiptPercent,
        'Tax' => Heroicon::OutlinedReceiptPercent,
        'Income tax' => Heroicon::OutlinedReceiptPercent,
        'Payment info' => Heroicon::OutlinedCreditCard,
        'Bank' => Heroicon::OutlinedBuildingLibrary,
        'Down payments' => Heroicon::OutlinedArrowDownTray,
        'Down payment' => Heroicon::OutlinedArrowDownTray,
        'Contacts' => Heroicon::OutlinedUserGroup,
        'Address' => Heroicon::OutlinedMapPin,
        'Billing address' => Heroicon::OutlinedMapPin,
        'Shipping' => Heroicon::OutlinedTruck,
        'Accounts' => Heroicon::OutlinedBookOpen,
        'Salary account' => Heroicon::OutlinedBookOpen,
        'Pay' => Heroicon::OutlinedBanknotes,
        'Expenditure accounts' => Heroicon::OutlinedBookOpen,
        'Users' => Heroicon::OutlinedUsers,
        'Employees' => Heroicon::OutlinedUsers,
        'Access groups' => Heroicon::OutlinedUserGroup,
        'Screen rights' => Heroicon::OutlinedShieldCheck,
        'Special rights' => Heroicon::OutlinedKey,
        'Targets' => Heroicon::OutlinedFlag,
        'Stock' => Heroicon::OutlinedCube,
        'Sales' => Heroicon::OutlinedShoppingCart,
        'Purchasing' => Heroicon::OutlinedTruck,
        'Sales / Purchasing' => Heroicon::OutlinedArrowsUpDown,
        'Prices' => Heroicon::OutlinedTag,
        'Numbering' => Heroicon::OutlinedHashtag,
        'Layout' => Heroicon::OutlinedPrinter,
        'Commission' => Heroicon::OutlinedPercentBadge,
        'Branches' => Heroicon::OutlinedBuildingOffice2,
    ];

    /** @var array<string, array<string, Heroicon>> locale → translated label → icon */
    private static array $byLabel = [];

    public static function for(string $label): ?Heroicon
    {
        $locale = app()->getLocale();
        if (! isset(self::$byLabel[$locale])) {
            self::$byLabel[$locale] = [];
            foreach (self::ICONS as $key => $icon) {
                self::$byLabel[$locale][(string) __($key)] ??= $icon;
            }
        }

        return self::$byLabel[$locale][$label] ?? null;
    }
}
