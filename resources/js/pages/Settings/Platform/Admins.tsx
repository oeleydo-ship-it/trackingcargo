import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';

interface PlatformAdmin {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: 'active' | 'invited' | 'suspended' | 'inactive';
}

interface PlatformAdminsProps {
    admins: PlatformAdmin[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    invited: 'text-cyan-300 border-cyan-400/30',
    suspended: 'text-rose-300 border-rose-400/30',
    inactive: 'text-slate-400 border-white/10',
};

export default function PlatformAdmins({ admins }: PlatformAdminsProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', email: '', phone: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/platform/admins', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const toggleStatus = (admin: PlatformAdmin) => {
        const action = admin.status === 'suspended' ? 'reactivate' : 'suspend';
        router.post(`/settings/platform/admins/${admin.id}/${action}`, {}, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Platform admins">
            <Head title="Platform admins" />
            <p className="mb-5 max-w-2xl text-sm text-slate-500">
                Accounts with unrestricted access to every company on this platform, including this Settings &rarr; Platform screen itself. This is separate from a company&apos;s own Users list, which only manages staff belonging to that one company.
            </p>

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{admins.length} platform admin{admins.length === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'Invite platform admin'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2">
                    <div>
                        <label htmlFor="name" className={labelClass}>Name</label>
                        <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>
                    <div>
                        <label htmlFor="email" className={labelClass}>Email</label>
                        <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required className={fieldClass} />
                        {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
                    </div>
                    <div>
                        <label htmlFor="phone" className={labelClass}>Phone</label>
                        <input id="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    </div>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Sending…' : 'Send invitation'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Phone</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {admins.map((admin) => (
                            <tr key={admin.id}>
                                <td className="px-4 py-3">
                                    <p className="font-medium">{admin.name}</p>
                                    <p className="text-xs text-slate-500">{admin.email}</p>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{admin.phone ?? '—'}</td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[admin.status] ?? 'border-white/10 text-slate-400'}`}>{admin.status}</span></td>
                                <td className="px-4 py-3 text-right">
                                    {admin.status !== 'invited' && (
                                        <button onClick={() => toggleStatus(admin)} className="text-xs text-slate-400 hover:text-white">
                                            {admin.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {admins.length === 0 && (
                            <tr><td colSpan={4} className="px-4 py-8 text-center text-slate-500">No platform admins yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </SettingsLayout>
    );
}
