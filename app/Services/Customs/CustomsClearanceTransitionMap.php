<?php

declare(strict_types=1);

namespace App\Services\Customs;

use App\Enums\CustomsClearanceStatus;

final class CustomsClearanceTransitionMap
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'pending' => ['under_review'],
        'under_review' => ['cleared', 'held', 'rejected'],
        'held' => ['under_review', 'rejected'],
        'cleared' => [],
        'rejected' => [],
    ];

    public static function isAllowed(CustomsClearanceStatus $from, CustomsClearanceStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /** @return list<CustomsClearanceStatus> */
    public static function allowedFrom(CustomsClearanceStatus $from): array
    {
        return array_map(
            static fn (string $status): CustomsClearanceStatus => CustomsClearanceStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
