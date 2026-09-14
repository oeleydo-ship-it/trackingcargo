import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { DeliveryZoneSummary } from '../../../types';

interface ZonesIndexProps {
    zones: DeliveryZoneSummary[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function ZonesIndex({ zones }: ZonesIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', name: '', branch_id: '' as number | '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/delivery-zones', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <AppLayout title="Delivery zones">
            <Head title="Delivery zones" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{zones.length} zone{zones.length === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New zone'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-3">
                    <div>
                        <label className={labelClass}>Code</label>
                        <input value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required className={fieldClass} placeholder="Z1" />
                        {errors.code && <p className="mt-1 text-xs text-rose-400">{errors.code}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Name</label>
                        <input value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Downtown" />
                    </div>
                    <div>
                        <label className={labelClass}>Branch ID</label>
                        <input value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass} placeholder="e.g. 1" />
                    </div>
                    <div className="sm:col-span-3">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create zone'}
                        </button>
                    </div>
                </form>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {zones.map((zone) => (
                    <div key={zone.id} className="rounded-xl border border-white/10 bg-white/[0.035] p-4">
                        <p className="font-mono text-xs text-slate-500">{zone.code}</p>
                        <p className="font-medium">{zone.name}</p>
                        <p className="text-xs text-slate-500">{zone.branch?.name ?? 'All branches'}</p>
                    </div>
                ))}
                {zones.length === 0 && <p className="text-sm text-slate-500">No delivery zones yet.</p>}
            </div>
        </AppLayout>
    );
}
