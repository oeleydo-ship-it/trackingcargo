import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { statusBadgeClass } from '../../lib/statusColors';
import type { AssignableShipment, ShipmentBatch, ShipmentSummary } from '../../types';

interface BatchShowProps {
    batch: ShipmentBatch;
    shipments: ShipmentSummary[];
    allowedTransitions: { value: string; label: string; applicable: number }[];
    assignable: AssignableShipment[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    draft: 'text-slate-400 border-white/10',
    booked: 'text-cyan-300 border-cyan-400/30',
    received: 'text-cyan-300 border-cyan-400/30',
    in_transit: 'text-blue-300 border-blue-400/30',
    at_customs: 'text-amber-300 border-amber-400/30',
    out_for_delivery: 'text-amber-300 border-amber-400/30',
    delivered: 'text-emerald-300 border-emerald-400/30',
    exception: 'text-rose-300 border-rose-400/30',
    cancelled: 'text-slate-500 border-white/10',
    returned: 'text-rose-300 border-rose-400/30',
};

export default function BatchShow({ batch, shipments, allowedTransitions, assignable }: BatchShowProps) {
    const isOpen = batch.status === 'open';

    return (
        <AppLayout title={batch.batch_number}>
            <Head title={batch.batch_number} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-slate-500">{batch.branch?.name ?? '—'}</p>
                    <h2 className="text-xl font-semibold">{batch.batch_number}</h2>
                    {batch.reference && <p className="mt-0.5 text-sm text-slate-400">{batch.reference}</p>}
                </div>
                <span className={`rounded-full border px-3 py-1 text-xs capitalize ${isOpen ? 'border-emerald-400/30 text-emerald-300' : 'border-white/10 text-slate-400'}`}>{batch.status}</span>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <MembersCard batch={batch} shipments={shipments} isOpen={isOpen} />
                    {isOpen && <AddShipmentsCard batch={batch} assignable={assignable} />}
                </div>
                <div className="space-y-6">
                    <BulkTransitionCard batch={batch} shipments={shipments} allowedTransitions={allowedTransitions} />
                    <BatchSettingsCard batch={batch} />
                </div>
            </div>
        </AppLayout>
    );
}

function MembersCard({ batch, shipments, isOpen }: { batch: ShipmentBatch; shipments: ShipmentSummary[]; isOpen: boolean }) {
    const remove = (shipment: ShipmentSummary) => {
        if (confirm(`Remove ${shipment.tracking_number} from this batch?`)) {
            router.delete(`/batches/${batch.id}/shipments/${shipment.id}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Shipments in this batch ({shipments.length})</p>
            <div className="mt-4 overflow-hidden rounded-xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Tracking #</th>
                            <th className="px-3 py-2">Destination</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {shipments.map((shipment) => (
                            <tr key={shipment.id}>
                                <td className="px-3 py-2 font-mono text-xs">
                                    <Link href={`/shipments/${shipment.id}`} className="text-cyan-300 hover:text-cyan-200">{shipment.tracking_number}</Link>
                                </td>
                                <td className="px-3 py-2 text-slate-400">{[shipment.destination_city, shipment.destination_country_code].filter(Boolean).join(', ') || '—'}</td>
                                <td className="px-3 py-2"><span className={`rounded-full border px-2 py-0.5 text-xs ${statusBadgeClass(shipment.shipment_status?.color)}`}>{shipment.shipment_status?.name ?? shipment.status.replace(/_/g, ' ')}</span></td>
                                <td className="px-3 py-2 text-right">
                                    {isOpen && <button onClick={() => remove(shipment)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>}
                                </td>
                            </tr>
                        ))}
                        {shipments.length === 0 && (
                            <tr><td colSpan={4} className="px-3 py-6 text-center text-slate-500">No shipments in this batch yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </article>
    );
}

function AddShipmentsCard({ batch, assignable }: { batch: ShipmentBatch; assignable: AssignableShipment[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({ shipments: [] as number[] });

    const toggle = (id: number) => {
        setData('shipments', data.shipments.includes(id) ? data.shipments.filter((value) => value !== id) : [...data.shipments, id]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/batches/${batch.id}/shipments`, { onSuccess: () => reset() });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Add shipments</p>
            <p className="mt-1 text-xs text-slate-500">Unbatched shipments in {batch.branch?.name ?? 'this branch'}.</p>
            {errors.shipments && <p className="mt-2 text-xs text-rose-400">{errors.shipments}</p>}

            {assignable.length === 0 ? (
                <p className="mt-4 rounded-xl border border-dashed border-white/10 p-6 text-center text-sm text-slate-500">
                    Every shipment in this branch is already in a batch.
                </p>
            ) : (
                <form onSubmit={submit} className="mt-4">
                    <div className="max-h-72 space-y-1.5 overflow-y-auto rounded-xl border border-white/10 p-3">
                        {assignable.map((shipment) => (
                            <label key={shipment.id} className="flex items-center gap-2 text-xs text-slate-300">
                                <input type="checkbox" checked={data.shipments.includes(shipment.id)} onChange={() => toggle(shipment.id)} className="size-3.5 rounded border-white/20 bg-white/5 text-cyan-400" />
                                <span className="font-mono">{shipment.tracking_number}</span>
                                <span className="text-slate-600">{[shipment.destination_city, shipment.destination_country_code].filter(Boolean).join(', ')}</span>
                                <span className="ml-auto capitalize text-slate-500">{shipment.status.replace(/_/g, ' ')}</span>
                            </label>
                        ))}
                    </div>
                    <button type="submit" disabled={processing || data.shipments.length === 0} className="mt-3 rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        {processing ? 'Adding…' : `Add ${data.shipments.length || ''} to batch`.trim()}
                    </button>
                </form>
            )}
        </article>
    );
}

function BulkTransitionCard({ batch, shipments, allowedTransitions }: { batch: ShipmentBatch; shipments: ShipmentSummary[]; allowedTransitions: { value: string; label: string; applicable: number }[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        status: '',
        location: '',
        description: '',
        is_public: true as boolean,
    });

    const selected = allowedTransitions.find((transition) => transition.value === data.status);
    const willSkip = selected ? shipments.length - selected.applicable : 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/batches/${batch.id}/transitions`, { onSuccess: () => reset() });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Bulk status update</p>
            <p className="mt-1 text-xs text-slate-500">
                Move every shipment in this batch to a new status at once — e.g. mark them all delivered the moment the batch reaches its destination. Each shipment still gets its own tracking event, and one already past the chosen status is simply skipped.
            </p>

            {shipments.length === 0 ? (
                <p className="mt-4 text-sm text-slate-500">Add shipments to the batch first.</p>
            ) : allowedTransitions.length === 0 ? (
                <p className="mt-4 rounded-xl border border-amber-400/20 bg-amber-400/5 p-3 text-xs text-amber-200">
                    Every shipment in this batch is already at a terminal status. There's nowhere left to bulk-move them to.
                </p>
            ) : (
                <form onSubmit={submit} className="mt-4 space-y-3">
                    <div>
                        <label htmlFor="status" className={labelClass}>New status</label>
                        <select id="status" value={data.status} onChange={(event) => setData('status', event.target.value)} required className={fieldClass}>
                            <option value="">Select a status…</option>
                            {allowedTransitions.map((transition) => (
                                <option key={transition.value} value={transition.value}>
                                    {transition.label} ({transition.applicable} of {shipments.length})
                                </option>
                            ))}
                        </select>
                        {errors.status && <p className="mt-1 text-xs text-rose-400">{errors.status}</p>}
                        {willSkip > 0 && (
                            <p className="mt-1.5 text-xs text-amber-300/80">
                                {willSkip} shipment{willSkip === 1 ? '' : 's'} already past this status will be skipped.
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="location" className={labelClass}>Location (optional)</label>
                        <input id="location" value={data.location} onChange={(event) => setData('location', event.target.value)} className={fieldClass} placeholder="Dubai hub" />
                    </div>
                    <div>
                        <label htmlFor="description" className={labelClass}>Description (optional)</label>
                        <input id="description" value={data.description} onChange={(event) => setData('description', event.target.value)} className={fieldClass} />
                    </div>
                    <label className="flex items-center gap-2 text-xs text-slate-400">
                        <input type="checkbox" checked={data.is_public} onChange={(event) => setData('is_public', event.target.checked)} className="size-3.5 rounded border-white/20 bg-white/5 text-cyan-400" />
                        Visible on public tracking
                    </label>
                    <button type="submit" disabled={processing || !data.status} className="w-full rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        {processing ? 'Updating…' : selected ? `Update ${selected.applicable} shipment${selected.applicable === 1 ? '' : 's'}` : `Update shipments`}
                    </button>
                </form>
            )}
        </article>
    );
}

function BatchSettingsCard({ batch }: { batch: ShipmentBatch }) {
    const [confirming, setConfirming] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({
        reference: batch.reference ?? '',
        notes: batch.notes ?? '',
        status: batch.status,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/batches/${batch.id}`);
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Batch details</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label htmlFor="batch-reference" className={labelClass}>Reference</label>
                    <input id="batch-reference" value={data.reference} onChange={(event) => setData('reference', event.target.value)} className={fieldClass} />
                    {errors.reference && <p className="mt-1 text-xs text-rose-400">{errors.reference}</p>}
                </div>
                <div>
                    <label htmlFor="batch-notes" className={labelClass}>Notes</label>
                    <textarea id="batch-notes" value={data.notes} onChange={(event) => setData('notes', event.target.value)} rows={3} className={fieldClass} />
                    {errors.notes && <p className="mt-1 text-xs text-rose-400">{errors.notes}</p>}
                </div>
                <div>
                    <label htmlFor="batch-status" className={labelClass}>Status</label>
                    <select id="batch-status" value={data.status} onChange={(event) => setData('status', event.target.value as typeof data.status)} className={fieldClass}>
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                    </select>
                    <p className="mt-1 text-xs text-slate-600">A closed batch can still be bulk-updated, but its membership is locked.</p>
                    {errors.status && <p className="mt-1 text-xs text-rose-400">{errors.status}</p>}
                </div>
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : 'Save changes'}
                </button>
            </form>

            <div className="mt-5 border-t border-white/5 pt-4">
                {confirming ? (
                    <div className="space-y-2">
                        <p className="text-xs text-slate-400">Delete this batch? Its shipments stay, ungrouped.</p>
                        <div className="flex gap-2">
                            <button onClick={() => router.delete(`/batches/${batch.id}`)} className="rounded-lg bg-rose-500 px-3 py-2 text-xs font-semibold text-on-accent transition hover:bg-rose-400">Delete batch</button>
                            <button onClick={() => setConfirming(false)} className="rounded-lg border border-white/10 px-3 py-2 text-xs text-slate-400 transition hover:text-slate-200">Cancel</button>
                        </div>
                    </div>
                ) : (
                    <button onClick={() => setConfirming(true)} className="text-xs text-rose-400 hover:text-rose-300">Delete batch</button>
                )}
            </div>
        </article>
    );
}
