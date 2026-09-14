import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { MasterSummary, Paginated } from '../../../types';

interface MastersIndexProps {
    masters: Paginated<MasterSummary>;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    open: 'text-cyan-300 border-cyan-400/30',
    closed: 'text-amber-300 border-amber-400/30',
    departed: 'text-blue-300 border-blue-400/30',
    arrived: 'text-emerald-300 border-emerald-400/30',
    closed_out: 'text-slate-400 border-white/10',
    cancelled: 'text-rose-300 border-rose-400/30',
};

export default function MastersIndex({ masters }: MastersIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: '' as number | '',
        mode: 'air' as 'air' | 'sea',
        carrier_code: '',
        flight_number: '',
        origin_airport: '',
        destination_airport: '',
        shipping_line: '',
        vessel_name: '',
        voyage_number: '',
        origin_port: '',
        destination_port: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/freight/masters', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <AppLayout title="Masters">
            <Head title="Masters" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{masters.total} master{masters.total === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New master'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label className={labelClass}>Branch ID</label>
                        <input value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} placeholder="e.g. 1" />
                        {errors.branch_id && <p className="mt-1 text-xs text-rose-400">{errors.branch_id}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Mode</label>
                        <select value={data.mode} onChange={(event) => setData('mode', event.target.value as 'air' | 'sea')} className={fieldClass}>
                            <option value="air">Air</option>
                            <option value="sea">Sea</option>
                        </select>
                    </div>
                    <div />

                    {data.mode === 'air' ? (
                        <>
                            <div>
                                <label className={labelClass}>Carrier code</label>
                                <input value={data.carrier_code} onChange={(event) => setData('carrier_code', event.target.value.toUpperCase())} required className={fieldClass} placeholder="EK" />
                                {errors.carrier_code && <p className="mt-1 text-xs text-rose-400">{errors.carrier_code}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Flight number</label>
                                <input value={data.flight_number} onChange={(event) => setData('flight_number', event.target.value.toUpperCase())} required className={fieldClass} placeholder="EK332" />
                                {errors.flight_number && <p className="mt-1 text-xs text-rose-400">{errors.flight_number}</p>}
                            </div>
                            <div />
                            <div>
                                <label className={labelClass}>Origin airport</label>
                                <input value={data.origin_airport} onChange={(event) => setData('origin_airport', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} placeholder="DXB" />
                                {errors.origin_airport && <p className="mt-1 text-xs text-rose-400">{errors.origin_airport}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Destination airport</label>
                                <input value={data.destination_airport} onChange={(event) => setData('destination_airport', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} placeholder="MNL" />
                                {errors.destination_airport && <p className="mt-1 text-xs text-rose-400">{errors.destination_airport}</p>}
                            </div>
                        </>
                    ) : (
                        <>
                            <div>
                                <label className={labelClass}>Shipping line</label>
                                <input value={data.shipping_line} onChange={(event) => setData('shipping_line', event.target.value)} required className={fieldClass} />
                                {errors.shipping_line && <p className="mt-1 text-xs text-rose-400">{errors.shipping_line}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Vessel name</label>
                                <input value={data.vessel_name} onChange={(event) => setData('vessel_name', event.target.value)} required className={fieldClass} />
                                {errors.vessel_name && <p className="mt-1 text-xs text-rose-400">{errors.vessel_name}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Voyage number</label>
                                <input value={data.voyage_number} onChange={(event) => setData('voyage_number', event.target.value)} required className={fieldClass} />
                                {errors.voyage_number && <p className="mt-1 text-xs text-rose-400">{errors.voyage_number}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Origin port</label>
                                <input value={data.origin_port} onChange={(event) => setData('origin_port', event.target.value)} required className={fieldClass} />
                                {errors.origin_port && <p className="mt-1 text-xs text-rose-400">{errors.origin_port}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Destination port</label>
                                <input value={data.destination_port} onChange={(event) => setData('destination_port', event.target.value)} required className={fieldClass} />
                                {errors.destination_port && <p className="mt-1 text-xs text-rose-400">{errors.destination_port}</p>}
                            </div>
                        </>
                    )}

                    <div className="sm:col-span-2 lg:col-span-3">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create master'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Master #</th>
                            <th className="px-4 py-3">Mode</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Packages</th>
                            <th className="px-4 py-3">Weight</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {masters.data.map((master) => (
                            <tr key={master.id}>
                                <td className="px-4 py-3 font-mono text-xs">
                                    <Link href={`/freight/masters/${master.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">{master.master_number}</Link>
                                </td>
                                <td className="px-4 py-3 text-slate-400 uppercase">{master.mode}</td>
                                <td className="px-4 py-3 text-slate-400">{master.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{master.package_count}</td>
                                <td className="px-4 py-3 text-slate-400">{master.weight_kg} kg</td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[master.status] ?? 'border-white/10 text-slate-400'}`}>{master.status.replace(/_/g, ' ')}</span></td>
                            </tr>
                        ))}
                        {masters.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">No masters yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {masters.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>{masters.from ?? 0}–{masters.to ?? 0} of {masters.total}</span>
                    <div className="flex gap-2">
                        <button disabled={masters.current_page <= 1} onClick={() => router.get('/freight/masters', { page: masters.current_page - 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Previous</button>
                        <button disabled={masters.current_page >= masters.last_page} onClick={() => router.get('/freight/masters', { page: masters.current_page + 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Next</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
