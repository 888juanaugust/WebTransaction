<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * What a screen is for, which decides the colour of its tile in the module
 * menu: setting the company up, doing the daily work, or looking things up.
 */
enum ScreenKind: string
{
    /** Masters and settings: what the rest of the books refer to. */
    case Setup = 'setup';

    /** Documents and processes: the daily work that posts to the books. */
    case Work = 'work';

    /** Inquiries, tools and reports: reading what the books hold. */
    case Tool = 'tool';

    public function label(): string
    {
        return match ($this) {
            self::Setup => __('Setup and masters'),
            self::Work => __('Transactions and processes'),
            self::Tool => __('Inquiries, tools and reports'),
        };
    }
}
