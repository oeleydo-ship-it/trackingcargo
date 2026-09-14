<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Opt-out, not opt-in: a channel is enabled unless the user has an explicit
 * row turning it off. This means a brand-new notification type is "on" for
 * everyone by default, appropriate for a B2B operations tool where a missed
 * delivery/invoice notice is worse than one unwanted email — the user can
 * still turn any specific (type, channel) pair off via /settings/notifications.
 */
final readonly class NotificationPreferenceService
{
    public function isEnabled(User $user, NotificationType $type, string $channel): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('notification_type', $type->value)
            ->where('channel', $channel)
            ->first();

        return $preference === null || $preference->enabled;
    }

    /** @param list<string> $candidateChannels */
    public function filterChannels(User $user, NotificationType $type, array $candidateChannels): array
    {
        return array_values(array_filter(
            $candidateChannels,
            fn (string $channel): bool => $this->isEnabled($user, $type, $channel),
        ));
    }

    public function setPreference(User $user, NotificationType $type, string $channel, bool $enabled): NotificationPreference
    {
        return NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'notification_type' => $type->value, 'channel' => $channel],
            ['enabled' => $enabled],
        );
    }
}
