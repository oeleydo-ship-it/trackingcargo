import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { DriverSummary, Paginated } from '../../../types';

interface DriversIndexProps {
    drivers: Paginated<DriverSummary>;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    inactive: 'text-slate-400 border-white/10',
    suspended: 'text-rose-300 border-rose-400/30',
};

export default function DriversIndex({ drivers }: DriversIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ user_id: '' as number | '', branch_id: '' as number | '', license_number: '', phone: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/drivers', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <AppLayout title="Drivers">
            <Head title="Drivers" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{drivers.total} driver{drivers.total === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'Link a driver'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className={labelClass}>User ID</label>
                        <input value={data.user_id} onChange={(event) => setData('user_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} placeholder="Existing user id" />
                        {errors.user_id && <p className="mt-1 text-xs text-rose-400">{errors.user_id}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Branch ID</label>
                        <input value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass} placeholder="e.g. 1" />
                    </div>
                    <div>
                        <label className={labelClass}>License number</label>
                        <input value={data.license_number} onChange={(event) => setData('license_number', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Phone</label>
                        <input value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    </div>
                    <div className="sm:col-span-2 lg:col-span-4">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Linking…' : 'Link driver'}
                        </button>
                        <p className="mt-2 text-xs text-slate-500">The user must already exist (invite them via Settings → Users first) and should hold the "driver" role to use the delivery app.</p>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Email</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Zone</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {drivers.data.map((driver) => (
                            <tr key={driver.id}>
                                <td className="px-4 py-3 font-medium">{driver.user.name}</td>
                                <td className="px-4 py-3 text-slate-400">{driver.user.email}</td>
                                <td className="px-4 py-3 text-slate-400">{driver.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{driver.zone?.name ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[driver.status] ?? 'border-white/10 text-slate-400'}`}>{driver.status}</span>
                                </td>
                            </tr>
                        ))}
                        {drivers.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No drivers linked yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
