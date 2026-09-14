<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomsClearanceStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Cleared = 'cleared';
    case Held = 'held';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under review',
            self::Cleared => 'Cleared',
            self::Held => 'Held',
            self::Rejected => 'Rejected',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cleared, self::Rejected => true,
            default => false,
        };
    }
}
