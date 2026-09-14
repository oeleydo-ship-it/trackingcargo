import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { LoadUnit, Master } from '../../../types';

interface ShowProps {
    master: Master;
    allowedTransitions: { value: string; label: string }[];
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
    building: 'text-slate-400 border-white/10',
    loaded: 'text-cyan-300 border-cyan-400/30',
    in_transit: 'text-blue-300 border-blue-400/30',
    unloaded: 'text-slate-400 border-white/10',
};

const unitTransitions: Record<string, string[]> = {
    building: ['loaded'],
    loaded: ['in_transit', 'building'],
    in_transit: ['arrived'],
    arrived: ['unloaded'],
    unloaded: [],
};

export default function Show({ master, allowedTransitions }: ShowProps) {
    return (
        <AppLayout title={master.master_number}>
            <Head title={master.master_number} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-slate-500">{master.mode.toUpperCase()} · {master.branch?.name ?? ''}</p>
                    <h2 className="text-xl font-semibold">{master.master_number}</h2>
                </div>
                <span className={`rounded-full border px-3 py-1 text-xs capitalize ${statusColor[master.status] ?? 'border-white/10 text-slate-400'}`}>{master.status.replace(/_/g, ' ')}</span>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <SummaryCard master={master} />
                    <LoadUnitsCard master={master} />
                    <ManifestsCard master={master} />
                </div>
                <div className="space-y-6">
                    <TransitionCard master={master} allowedTransitions={allowedTransitions} />
                </div>
            </div>
        </AppLayout>
    );
}

function SummaryCard({ master }: { master: Master }) {
    const route = master.mode === 'air'
        ? `${master.origin_airport ?? '—'} → ${master.destination_airport ?? '—'}`
        : `${master.origin_port ?? '—'} → ${master.destination_port ?? '—'}`;
    const conveyance = master.mode === 'air'
        ? `${master.carrier_code ?? ''} ${master.flight_number ?? ''}`.trim()
        : `${master.shipping_line ?? ''} ${master.vessel_name ?? ''} ${master.voyage_number ?? ''}`.trim();

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Summary</p>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-slate-500">Conveyance</dt><dd>{conveyance || '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Route</dt><dd>{route}</dd></div>
                <div><dt className="text-xs text-slate-500">Branch</dt><dd>{master.branch?.name ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Load units</dt><dd>{master.load_units.length}</dd></div>
                <div><dt className="text-xs text-slate-500">Packages</dt><dd>{master.package_count}</dd></div>
                <div><dt className="text-xs text-slate-500">Weight</dt><dd className="font-semibold text-cyan-300">{master.weight_kg} kg</dd></div>
            </dl>
        </article>
    );
}

function LoadUnitsCard({ master }: { master: Master }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ type: 'pallet' as 'container' | 'pallet' | 'bag', unit_number: '', seal_number: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/freight/masters/${master.id}/load-units`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const transition = (unitId: number, status: string) => {
        router.post(`/freight/masters/${master.id}/load-units/${unitId}/transitions`, { status }, { preserveScroll: true });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Load units</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <select value={data.type} onChange={(event) => setData('type', event.target.value as typeof data.type)} className={fieldClass}>
                        <option value="pallet">Pallet</option>
                        <option value="bag">Bag</option>
                        <option value="container">Container</option>
                    </select>
                    <input placeholder="Unit number" value={data.unit_number} onChange={(event) => setData('unit_number', event.target.value)} required className={fieldClass} />
                    <input placeholder="Seal number" value={data.seal_number} onChange={(event) => setData('seal_number', event.target.value)} className={fieldClass} />
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add</button>
                    {errors.unit_number && <p className="col-span-full text-xs text-rose-400">{errors.unit_number}</p>}
                </form>
            )}

            <div className="mt-4 space-y-4">
                {master.load_units.map((unit) => (
                    <LoadUnitRow key={unit.id} master={master} unit={unit} onTransition={transition} />
                ))}
                {master.load_units.length === 0 && <p className="text-sm text-slate-500">No load units yet.</p>}
            </div>
        </article>
    );
}

function LoadUnitRow({ master, unit, onTransition }: { master: Master; unit: LoadUnit; onTransition: (unitId: number, status: string) => void }) {
    const [showLoadForm, setShowLoadForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ package_id: '' as number | '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/freight/masters/${master.id}/load-units/${unit.id}/packages`, { onSuccess: () => { reset(); setShowLoadForm(false); } });
    };

    const unload = (packageId: number) => {
        router.delete(`/freight/masters/${master.id}/load-units/${unit.id}/packages/${packageId}`, { preserveScroll: true });
    };

    return (
        <div className="rounded-xl border border-white/5 p-3">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-medium capitalize">{unit.type} {unit.unit_number}{unit.seal_number ? ` · Seal ${unit.seal_number}` : ''}</p>
                    <p className="text-xs text-slate-500">{unit.package_count} package(s), {unit.weight_kg} kg</p>
                </div>
                <div className="flex items-center gap-2">
                    <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[unit.status] ?? 'border-white/10 text-slate-400'}`}>{unit.status.replace(/_/g, ' ')}</span>
                    {unitTransitions[unit.status]?.length > 0 && (
                        <select
                            defaultValue=""
                            onChange={(event) => { if (event.target.value) onTransition(unit.id, event.target.value); event.target.value = ''; }}
                            className="rounded-lg border border-white/10 bg-transparent px-2 py-1 text-xs text-slate-400"
                        >
                            <option value="">Advance…</option>
                            {unitTransitions[unit.status].map((status) => (
                                <option key={status} value={status}>{status.replace(/_/g, ' ')}</option>
                            ))}
                        </select>
                    )}
                    {unit.status === 'building' && (
                        <button onClick={() => setShowLoadForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showLoadForm ? 'Cancel' : '+ Load package'}</button>
                    )}
                </div>
            </div>

            {showLoadForm && (
                <form onSubmit={submit} className="mt-3 flex gap-2">
                    <input placeholder="Package ID" value={data.package_id} onChange={(event) => setData('package_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                    <button type="submit" disabled={processing} className="shrink-0 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Load</button>
                </form>
            )}
            {errors.package_id && <p className="mt-1 text-xs text-rose-400">{errors.package_id}</p>}

            {unit.packages.length > 0 && (
                <div className="mt-3 space-y-1.5">
                    {unit.packages.map((pkg) => (
                        <div key={pkg.id} className="flex items-center justify-between text-xs text-slate-400">
                            <span className="font-mono">{pkg.barcode} · {pkg.shipment.tracking_number} · {pkg.weight_kg} kg</span>
                            {unit.status === 'building' && <button onClick={() => unload(pkg.id)} className="text-rose-400 hover:text-rose-300">Unload</button>}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function ManifestsCard({ master }: { master: Master }) {
    const generate = () => {
        router.post(`/freight/masters/${master.id}/manifests`);
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Manifests</p>
                <button onClick={generate} className="text-xs text-cyan-300 hover:text-cyan-200">+ Generate</button>
            </div>
            <div className="mt-4 space-y-2">
                {master.manifests.map((manifest) => (
                    <a
                        key={manifest.id}
                        href={`/freight/masters/${master.id}/manifests/${manifest.id}`}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm hover:border-cyan-400/30"
                    >
                        <span className="font-mono">{manifest.manifest_number}</span>
                        <span className="text-xs text-slate-500">v{manifest.version} · {new Date(manifest.created_at).toLocaleString()}</span>
                    </a>
                ))}
                {master.manifests.length === 0 && <p className="text-sm text-slate-500">No manifests generated yet.</p>}
            </div>
        </article>
    );
}

function TransitionCard({ master, allowedTransitions }: { master: Master; allowedTransitions: { value: string; label: string }[] }) {
    const { data, setData, post, processing, errors } = useForm({ status: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/freight/masters/${master.id}/transitions`);
    };

    if (allowedTransitions.length === 0) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <p className="text-sm font-semibold">Status</p>
                <p className="mt-3 text-sm text-slate-500">This master is in a terminal state.</p>
            </article>
        );
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Update status</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <select value={data.status} onChange={(event) => setData('status', event.target.value)} required className={fieldClass}>
                    <option value="" disabled>Select a status…</option>
                    {allowedTransitions.map((transition) => (
                        <option key={transition.value} value={transition.value}>{transition.label}</option>
                    ))}
                </select>
                {errors.status && <p className="text-xs text-rose-400">{errors.status}</p>}
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Apply</button>
            </form>
        </article>
    );
}
