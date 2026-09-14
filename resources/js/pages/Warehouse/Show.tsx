import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { Warehouse, WarehouseZone } from '../../types';

interface ShowProps {
    warehouse: Warehouse;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const zoneTypeLabel: Record<string, string> = {
    receiving: 'Receiving',
    storage: 'Storage',
    staging: 'Staging',
    dispatch: 'Dispatch',
    customs_hold: 'Customs hold',
    returns: 'Returns',
};

export default function Show({ warehouse }: ShowProps) {
    return (
        <AppLayout title={warehouse.name}>
            <Head title={warehouse.name} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-slate-500">{warehouse.branch?.name ?? 'Unassigned branch'}</p>
                    <h2 className="text-xl font-semibold">{warehouse.code} · {warehouse.name}</h2>
                </div>
                <Link
                    href={`/warehouses/${warehouse.id}/scan-board`}
                    className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300"
                >
                    Open scan board
                </Link>
            </div>

            <div className="space-y-6">
                <SummaryCard warehouse={warehouse} />
                <ZonesCard warehouse={warehouse} />
            </div>
        </AppLayout>
    );
}

function SummaryCard({ warehouse }: { warehouse: Warehouse }) {
    const locationCount = warehouse.zones.reduce((total, zone) => total + zone.locations.length, 0);
    const packageCount = warehouse.zones.reduce((total, zone) => total + zone.locations.reduce((sum, location) => sum + location.package_count, 0), 0);

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Summary</p>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                <div><dt className="text-xs text-slate-500">City</dt><dd>{warehouse.city ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Zones</dt><dd>{warehouse.zones.length}</dd></div>
                <div><dt className="text-xs text-slate-500">Locations</dt><dd>{locationCount}</dd></div>
                <div><dt className="text-xs text-slate-500">Packages on hand</dt><dd className="font-semibold text-cyan-300">{packageCount}</dd></div>
            </dl>
        </article>
    );
}

function ZonesCard({ warehouse }: { warehouse: Warehouse }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', name: '', type: 'storage' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/warehouses/${warehouse.id}/zones`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Zones</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add zone'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <input placeholder="Code" value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required className={fieldClass} />
                    <input placeholder="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    <select value={data.type} onChange={(event) => setData('type', event.target.value)} className={fieldClass}>
                        {Object.entries(zoneTypeLabel).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add</button>
                    {errors.code && <p className="col-span-full text-xs text-rose-400">{errors.code}</p>}
                </form>
            )}

            <div className="mt-4 space-y-4">
                {warehouse.zones.map((zone) => (
                    <ZoneRow key={zone.id} warehouse={warehouse} zone={zone} />
                ))}
                {warehouse.zones.length === 0 && <p className="text-sm text-slate-500">No zones yet.</p>}
            </div>
        </article>
    );
}

function ZoneRow({ warehouse, zone }: { warehouse: Warehouse; zone: WarehouseZone }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/warehouses/${warehouse.id}/zones/${zone.id}/locations`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <div className="rounded-xl border border-white/5 p-3">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-medium">{zone.code} · {zone.name}</p>
                    <p className="text-xs text-slate-500">{zoneTypeLabel[zone.type] ?? zone.type} · {zone.locations.length} location(s)</p>
                </div>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add location'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-3 flex gap-2">
                    <input placeholder="Location code (e.g. A-01-03)" value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required className={fieldClass} />
                    <button type="submit" disabled={processing} className="shrink-0 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add</button>
                </form>
            )}
            {errors.code && <p className="mt-1 text-xs text-rose-400">{errors.code}</p>}

            {zone.locations.length > 0 && (
                <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {zone.locations.map((location) => (
                        <div key={location.id} className="rounded-lg border border-white/5 px-3 py-2 text-xs">
                            <p className="font-mono text-slate-300">{location.code}</p>
                            <p className="text-slate-500">{location.package_count} package(s)</p>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
