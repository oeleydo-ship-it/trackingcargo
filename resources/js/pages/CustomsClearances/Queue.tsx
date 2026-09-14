import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../layouts/AppLayout';
import type { CustomsQueueItem } from '../../types';

interface QueueProps {
    clearances: CustomsQueueItem[];
}

const statusColor: Record<string, string> = {
    pending: 'text-slate-400 border-white/10',
    under_review: 'text-blue-300 border-blue-400/30',
    held: 'text-amber-300 border-amber-400/30',
};

export default function Queue({ clearances }: QueueProps) {
    return (
        <AppLayout title="Customs queue">
            <Head title="Customs queue" />

            <div className="mb-5">
                <p className="text-sm text-slate-400">{clearances.length} clearance{clearances.length === 1 ? '' : 's'} awaiting action</p>
            </div>

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Tracking #</th>
                            <th className="px-4 py-3">Destination</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Declaration #</th>
                            <th className="px-4 py-3">Customs office</th>
                            <th className="px-4 py-3">Submitted</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {clearances.map((clearance) => (
                            <tr key={clearance.id} className="hover:bg-white/[0.02]">
                                <td className="px-4 py-3">
                                    <Link href={`/shipments/${clearance.shipment.id}/customs-clearances/${clearance.id}`} className="font-mono text-cyan-300 hover:text-cyan-200">
                                        {clearance.shipment.tracking_number}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{[clearance.shipment.destination_city, clearance.shipment.destination_country_code].filter(Boolean).join(', ') || '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{clearance.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{clearance.declaration_number ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{clearance.customs_office ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{clearance.submitted_at ? new Date(clearance.submitted_at).toLocaleDateString() : '—'}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[clearance.status] ?? 'border-white/10 text-slate-400'}`}>{clearance.status.replace(/_/g, ' ')}</span>
                                </td>
                            </tr>
                        ))}
                        {clearances.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-500">Nothing waiting — every clearance is cleared or rejected.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
