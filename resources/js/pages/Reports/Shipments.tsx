import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';

interface ReportShipmentRow {
    id: number;
    tracking_number: string;
    mode: string;
    status: string;
    chargeable_weight_kg: string;
    booked_at: string | null;
    delivered_at: string | null;
    branch: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
}

interface ShipmentsReportProps {
    filters: { from: string; to: string; status?: string };
    rows: ReportShipmentRow[];
    canExport: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function ShipmentsReport({ filters, rows, canExport }: ShipmentsReportProps) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [status, setStatus] = useState(filters.status ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/reports/shipments', { from, to, status: status || undefined }, { preserveState: true });
    };

    const exportUrl = `/reports/shipments/export?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}${status ? `&status=${encodeURIComponent(status)}` : ''}`;

    return (
        <AppLayout title="Shipments report">
            <Head title="Shipments report" />

            <form onSubmit={submit} className="mb-6 flex flex-wrap items-end gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <div>
                    <label htmlFor="report-from" className={labelClass}>From</label>
                    <input id="report-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="report-to" className={labelClass}>To</label>
                    <input id="report-to" type="date" value={to} onChange={(event) => setTo(event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="report-status" className={labelClass}>Status (optional)</label>
                    <select id="report-status" value={status} onChange={(event) => setStatus(event.target.value)} className={fieldClass}>
                        <option value="">Any</option>
                        <option value="draft">Draft</option>
                        <option value="booked">Booked</option>
                        <option value="in_transit">In transit</option>
                        <option value="at_customs">At customs</option>
                        <option value="out_for_delivery">Out for delivery</option>
                        <option value="delivered">Delivered</option>
                        <option value="exception">Exception</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <button type="submit" className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">Apply</button>
                {canExport && (
                    <a href={exportUrl} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                        Export CSV
                    </a>
                )}
            </form>

            <p className="mb-3 text-sm text-slate-400">{rows.length} shipment{rows.length === 1 ? '' : 's'} (showing up to 200 — export for the full range)</p>

            <div className="overflow-x-auto rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Tracking #</th>
                            <th className="px-4 py-3">Mode</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Customer</th>
                            <th className="px-4 py-3">Booked</th>
                            <th className="px-4 py-3">Delivered</th>
                            <th className="px-4 py-3">Chargeable kg</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-mono">{row.tracking_number}</td>
                                <td className="px-4 py-3 text-slate-400 uppercase">{row.mode}</td>
                                <td className="px-4 py-3 text-slate-400 capitalize">{row.status.replace(/_/g, ' ')}</td>
                                <td className="px-4 py-3 text-slate-400">{row.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{row.customer?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{row.booked_at ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{row.delivered_at ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{row.chargeable_weight_kg}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-500">No shipments in this range.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
