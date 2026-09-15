import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import type { SharedPageProps } from '../types';
import AppLayout from './AppLayout';

const tabs = [
    ['Company', '/settings/company'],
    ['Branches', '/settings/branches'],
    ['Tracking numbers', '/settings/tracking'],
    ['Batches', '/settings/batches'],
    ['Users', '/settings/users'],
    ['Roles & permissions', '/settings/roles'],
    ['Webhooks', '/settings/webhook-endpoints'],
    ['Notifications', '/settings/notifications'],
    ['Shipment statuses', '/settings/shipment-statuses'],
    ['Carriers', '/settings/carriers'],
    ['Boxes', '/settings/boxes'],
    ['Audit log', '/settings/audit-log'],
] as const;

interface SettingsLayoutProps extends PropsWithChildren {
    title: string;
}

export default function SettingsLayout({ title, children }: SettingsLayoutProps) {
    const { url: currentUrl } = usePage();
    const { auth } = usePage<SharedPageProps>().props;

    const visibleTabs: readonly (readonly [string, string])[] = auth.user?.isPlatformAdmin
        ? [...tabs, ['Superadmin console', '/superadmin'], ['Failed jobs', '/settings/failed-jobs'], ['Platform', '/settings/platform'], ['Platform admins', '/settings/platform/admins']]
        : tabs;

    return (
        <AppLayout title={title}>
            <nav className="mb-6 flex flex-wrap gap-2 border-b border-white/10 pb-4" aria-label="Settings sections">
                {visibleTabs.map(([label, href]) => {
                    // Exact or a sub-path only — startsWith(href) alone would
                    // also light up "Platform" while viewing "Platform
                    // admins" (/settings/platform is a prefix of
                    // /settings/platform/admins).
                    const active = currentUrl === href || currentUrl.startsWith(`${href}/`);

                    return (
                        <Link
                            key={href}
                            href={href}
                            className={`rounded-lg px-3 py-2 text-sm font-medium transition ${active ? 'bg-cyan-400/10 text-cyan-300' : 'text-slate-400 hover:bg-white/5 hover:text-white'}`}
                        >
                            {label}
                        </Link>
                    );
                })}
            </nav>
            {children}
        </AppLayout>
    );
}
