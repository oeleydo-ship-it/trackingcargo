import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Paginated } from '../../../types';

interface AuditLogEntry {
    id: number;
    action: string;
    subject_type: string | null;
    subject_id: number | null;
    tracking_numbers: string[];
    user: { id: number; name: string } | null;
    ip_address: string | null;
    created_at: string;
}

interface AuditLogIndexProps {
    logs: Paginated<AuditLogEntry>;
    filters: { action: string };
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';

export default function AuditLogIndex({ logs, filters }: AuditLogIndexProps) {
    const [action, setAction] = useState(filters.action);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/settings/audit-log', { action: action || undefined }, { preserveState: true });
    };

    return (
        <SettingsLayout title="Audit log">
            <Head title="Audit log" />

            <form onSubmit={submit} className="mb-5 flex gap-3">
                <input
                    value={action}
                    onChange={(event) => setAction(event.target.value)}
                    placeholder="Filter by action, e.g. invoice.status-changed"
                    className={`${fieldClass} max-w-sm`}
                />
                <button type="submit" className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">Filter</button>
            </form>

            <p className="mb-3 text-sm text-slate-400">{logs.total} entr{logs.total === 1 ? 'y' : 'ies'}</p>

            <div className="overflow-x-auto rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Action</th>
                            <th className="px-4 py-3">Tracking number</th>
                            <th className="px-4 py-3">Subject</th>
                            <th className="px-4 py-3">User</th>
                            <th className="px-4 py-3">IP</th>
                            <th className="px-4 py-3">When</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {logs.data.map((entry) => (
                            <tr key={entry.id}>
                                <td className="px-4 py-3 font-mono text-xs">{entry.action}</td>
                                <td className="px-4 py-3 font-mono text-xs"><TrackingNumbers numbers={entry.tracking_numbers} /></td>
                                <td className="px-4 py-3 text-slate-400">{entry.subject_type ? `${entry.subject_type.split('\\').pop()} #${entry.subject_id}` : '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{entry.user?.name ?? 'System'}</td>
                                <td className="px-4 py-3 text-slate-500">{entry.ip_address ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-500">{new Date(entry.created_at).toLocaleString()}</td>
                            </tr>
                        ))}
                        {logs.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">No matching entries.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {logs.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>Page {logs.current_page} of {logs.last_page}</span>
                    <div className="flex gap-2">
                        {logs.current_page > 1 && (
                            <button onClick={() => router.get('/settings/audit-log', { action: action || undefined, page: logs.current_page - 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 hover:text-white">Previous</button>
                        )}
                        {logs.current_page < logs.last_page && (
                            <button onClick={() => router.get('/settings/audit-log', { action: action || undefined, page: logs.current_page + 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 hover:text-white">Next</button>
                        )}
                    </div>
                </div>
            )}
        </SettingsLayout>
    );
}

// Batch entries can touch dozens of shipments; show the first few and count the rest.
function TrackingNumbers({ numbers }: { numbers: string[] }) {
    if (numbers.length === 0) {
        return <span className="text-slate-600">—</span>;
    }

    const shown = numbers.slice(0, 3);

    return (
        <span title={numbers.join(', ')}>
            {shown.join(', ')}
            {numbers.length > shown.length && <span className="text-slate-500"> +{numbers.length - shown.length} more</span>}
        </span>
    );
}
