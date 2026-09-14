import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { Paginated, WarehouseSummary } from '../../types';

interface WarehouseIndexProps {
    warehouses: Paginated<WarehouseSummary>;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function WarehouseIndex({ warehouses }: WarehouseIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: '' as number | '',
        code: '',
        name: '',
        city: '',
        country_code: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/warehouses', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <AppLayout title="Warehouses">
            <Head title="Warehouses" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{warehouses.total} warehouse{warehouses.total === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New warehouse'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label className={labelClass}>Code</label>
                        <input value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required className={fieldClass} placeholder="WH1" />
                        {errors.code && <p className="mt-1 text-xs text-rose-400">{errors.code}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Name</label>
                        <input value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Dubai Hub" />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Branch ID</label>
                        <input value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass} placeholder="e.g. 1" />
                        {errors.branch_id && <p className="mt-1 text-xs text-rose-400">{errors.branch_id}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>City</label>
                        <input value={data.city} onChange={(event) => setData('city', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Country code</label>
                        <input value={data.country_code} onChange={(event) => setData('country_code', event.target.value.toUpperCase())} maxLength={2} className={fieldClass} placeholder="AE" />
                        {errors.country_code && <p className="mt-1 text-xs text-rose-400">{errors.country_code}</p>}
                    </div>

                    <div className="sm:col-span-2 lg:col-span-3">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create warehouse'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">City</th>
                            <th className="px-4 py-3">Locations</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {warehouses.data.map((warehouse) => (
                            <tr key={warehouse.id}>
                                <td className="px-4 py-3 font-mono text-xs">
                                    <Link href={`/warehouses/${warehouse.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">{warehouse.code}</Link>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{warehouse.name}</td>
                                <td className="px-4 py-3 text-slate-400">{warehouse.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{warehouse.city ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{warehouse.locations_count}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded-full border px-2 py-0.5 text-xs ${warehouse.is_active ? 'border-emerald-400/30 text-emerald-300' : 'border-white/10 text-slate-500'}`}>
                                        {warehouse.is_active ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                        {warehouses.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">No warehouses yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {warehouses.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>{warehouses.from ?? 0}–{warehouses.to ?? 0} of {warehouses.total}</span>
                    <div className="flex gap-2">
                        <button disabled={warehouses.current_page <= 1} onClick={() => router.get('/warehouses', { page: warehouses.current_page - 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Previous</button>
                        <button disabled={warehouses.current_page >= warehouses.last_page} onClick={() => router.get('/warehouses', { page: warehouses.current_page + 1 }, { preserveState: true })} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Next</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
