import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { Paginated, Vehicle, VehicleType } from '../../../types';

interface VehiclesIndexProps {
    vehicles: Paginated<Vehicle>;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    maintenance: 'text-amber-300 border-amber-400/30',
    inactive: 'text-slate-400 border-white/10',
};

export default function VehiclesIndex({ vehicles }: VehiclesIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ registration_number: '', type: 'van' as VehicleType, capacity_kg: '', branch_id: '' as number | '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/vehicles', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <AppLayout title="Vehicles">
            <Head title="Vehicles" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{vehicles.total} vehicle{vehicles.total === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New vehicle'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label className={labelClass}>Registration number</label>
                        <input value={data.registration_number} onChange={(event) => setData('registration_number', event.target.value.toUpperCase())} required className={fieldClass} />
                        {errors.registration_number && <p className="mt-1 text-xs text-rose-400">{errors.registration_number}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Type</label>
                        <select value={data.type} onChange={(event) => setData('type', event.target.value as VehicleType)} className={fieldClass}>
                            <option value="van">Van</option>
                            <option value="truck">Truck</option>
                            <option value="motorcycle">Motorcycle</option>
                            <option value="car">Car</option>
                        </select>
                    </div>
                    <div>
                        <label className={labelClass}>Capacity (kg)</label>
                        <input value={data.capacity_kg} onChange={(event) => setData('capacity_kg', event.target.value)} type="number" step="0.001" className={fieldClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Branch ID</label>
                        <input value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass} placeholder="e.g. 1" />
                    </div>
                    <div className="sm:col-span-2 lg:col-span-4">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create vehicle'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Registration</th>
                            <th className="px-4 py-3">Type</th>
                            <th className="px-4 py-3">Capacity</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {vehicles.data.map((vehicle) => (
                            <tr key={vehicle.id}>
                                <td className="px-4 py-3 font-mono text-xs font-semibold">{vehicle.registration_number}</td>
                                <td className="px-4 py-3 capitalize text-slate-400">{vehicle.type}</td>
                                <td className="px-4 py-3 text-slate-400">{vehicle.capacity_kg ? `${vehicle.capacity_kg} kg` : '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{vehicle.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[vehicle.status] ?? 'border-white/10 text-slate-400'}`}>{vehicle.status}</span>
                                </td>
                            </tr>
                        ))}
                        {vehicles.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No vehicles yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
