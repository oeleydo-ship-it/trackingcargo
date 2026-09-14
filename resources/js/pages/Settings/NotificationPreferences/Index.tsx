import { Head, router } from '@inertiajs/react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { NotificationTypePreference } from '../../../types';

interface NotificationPreferencesIndexProps {
    types: NotificationTypePreference[];
}

const channelLabels: Record<string, string> = {
    mail: 'Email',
    database: 'In-app',
    broadcast: 'Realtime',
};

export default function NotificationPreferencesIndex({ types }: NotificationPreferencesIndexProps) {
    const toggle = (typeValue: string, channel: string, currentlyEnabled: boolean) => {
        router.patch('/settings/notifications', {
            notification_type: typeValue,
            channel,
            enabled: !currentlyEnabled,
        }, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Notification preferences">
            <Head title="Notification preferences" />

            <p className="mb-5 text-sm text-slate-400">
                Choose which channels you receive each notification on. Everything is on by default.
            </p>

            <div className="space-y-4">
                {types.map((type) => (
                    <article key={type.value} className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                        <p className="text-sm font-semibold">{type.label}</p>
                        <div className="mt-4 flex flex-wrap gap-6">
                            {type.channels.map((entry) => (
                                <label key={entry.channel} className="flex items-center gap-2 text-sm text-slate-300">
                                    <input
                                        type="checkbox"
                                        checked={entry.enabled}
                                        onChange={() => toggle(type.value, entry.channel, entry.enabled)}
                                        className="size-4 rounded border-white/20 bg-white/5 text-cyan-400"
                                    />
                                    {channelLabels[entry.channel] ?? entry.channel}
                                </label>
                            ))}
                        </div>
                    </article>
                ))}
            </div>
        </SettingsLayout>
    );
}
