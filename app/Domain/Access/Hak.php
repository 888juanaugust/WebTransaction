<?php

declare(strict_types=1);

namespace App\Domain\Access;

/** The five rights a group holds on a screen, as the standard's Access Groups matrix has them. */
enum Hak: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Print = 'print';

    public function column(): string
    {
        return 'can_'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => __('View'),
            self::Create => __('Create'),
            self::Update => __('Edit'),
            self::Delete => __('Delete'),
            self::Print => __('Print'),
        };
    }

    /** Maps a Laravel / Filament ability name onto a right; null for an ability the matrix does not know. */
    public static function fromAbility(string $ability): ?self
    {
        return match ($ability) {
            'viewAny', 'view' => self::View,
            'create', 'replicate' => self::Create,
            'update', 'reorder', 'restore', 'restoreAny' => self::Update,
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny' => self::Delete,
            'print' => self::Print,
            default => null,
        };
    }
}
