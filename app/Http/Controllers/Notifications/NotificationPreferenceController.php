<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferenceRequest;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationPreferenceController extends Controller
{
    public function index(): Response
    {
        $user = request()->user();

        $existing = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => "{$preference->notification_type}:{$preference->channel}");

        $types = array_map(fn (NotificationType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'channels' => array_map(fn (string $channel): array => [
                'channel' => $channel,
                'enabled' => ($existing["{$type->value}:{$channel}"] ?? null)?->enabled ?? true,
            ], $type->channels()),
        ], NotificationType::cases());

        return Inertia::render('Settings/NotificationPreferences/Index', ['types' => $types]);
    }

    public function update(UpdateNotificationPreferenceRequest $request, NotificationPreferenceService $preferences): RedirectResponse
    {
        $data = $request->validated();

        $preferences->setPreference(
            $request->user(),
            NotificationType::from($data['notification_type']),
            $data['channel'],
            $data['enabled'],
        );

        return back()->with('success', 'Preference saved.');
    }
}
