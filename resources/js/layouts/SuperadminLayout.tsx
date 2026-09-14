import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLayout from './AppLayout';

export default function SuperadminLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { url } = usePage();
    return <AppLayout title={title}>
        <p className="mb-4 text-sm text-amber-200">Platform administrator · Controls across all workspaces. Company users cannot access this console.</p>
        <nav aria-label="Superadmin sections" className="mb-6 flex flex-wrap gap-3 border-b border-white/10 pb-4">
            {[
                ['Workspaces', '/superadmin'], ['All users', '/superadmin/users'], ['SMTP, branding & gateway', '/settings/platform'],
                ['Platform admins', '/settings/platform/admins'], ['Audit log', '/settings/audit-log'], ['Failed jobs', '/settings/failed-jobs'],
            ].map(([label, href]) => <Link key={href} href={href} className={`rounded-lg px-3 py-2 text-sm ${url.split('?')[0] === href ? 'bg-cyan-400/10 text-cyan-300' : 'bg-white/5 text-slate-300'}`}>{label}</Link>)}
        </nav>
        {children}
    </AppLayout>;
}
