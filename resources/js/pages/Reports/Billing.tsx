import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';

interface ReportInvoiceRow {
    id: number;
    invoice_number: string;
    status: string;
    currency: string;
    total: string;
    amount_paid: string;
    balance_due: string;
    issue_date: string | null;
    due_date: string | null;
    customer: { id: number; name: string } | null;
}

interface BillingReportProps {
    filters: { from: string; to: string; status?: string };
    rows: ReportInvoiceRow[];
    totals: { total: string; amountPaid: string; balanceDue: string };
    canExport: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function BillingReport({ filters, rows, totals, canExport }: BillingReportProps) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [status, setStatus] = useState(filters.status ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/reports/billing', { from, to, status: status || undefined }, { preserveState: true });
    };

    const exportUrl = `/reports/billing/export?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}${status ? `&status=${encodeURIComponent(status)}` : ''}`;

    return (
        <AppLayout title="Billing report">
            <Head title="Billing report" />

            <form onSubmit={submit} className="mb-6 flex flex-wrap items-end gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <div>
                    <label htmlFor="billing-report-from" className={labelClass}>Issued from</label>
                    <input id="billing-report-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="billing-report-to" className={labelClass}>Issued to</label>
                    <input id="billing-report-to" type="date" value={to} onChange={(event) => setTo(event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="billing-report-status" className={labelClass}>Status (optional)</label>
                    <select id="billing-report-status" value={status} onChange={(event) => setStatus(event.target.value)} className={fieldClass}>
                        <option value="">Any</option>
                        <option value="issued">Issued</option>
                        <option value="partially_paid">Partially paid</option>
                        <option value="paid">Paid</option>
                        <option value="void">Void</option>
                    </select>
                </div>
                <button type="submit" className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">Apply</button>
                {canExport && (
                    <a href={exportUrl} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                        Export CSV
                    </a>
                )}
            </form>

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                    <p className="text-sm text-slate-400">Total invoiced</p>
                    <p className="mt-3 text-2xl font-semibold text-slate-200">{totals.total}</p>
                </article>
                <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                    <p className="text-sm text-slate-400">Total paid</p>
                    <p className="mt-3 text-2xl font-semibold text-emerald-300">{totals.amountPaid}</p>
                </article>
                <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                    <p className="text-sm text-slate-400">Outstanding balance</p>
                    <p className="mt-3 text-2xl font-semibold text-amber-300">{totals.balanceDue}</p>
                </article>
            </div>

            <p className="mb-3 text-sm text-slate-400">{rows.length} invoice{rows.length === 1 ? '' : 's'} (showing up to 200 — export for the full range)</p>

            <div className="overflow-x-auto rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Invoice #</th>
                            <th className="px-4 py-3">Customer</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Total</th>
                            <th className="px-4 py-3">Paid</th>
                            <th className="px-4 py-3">Balance due</th>
                            <th className="px-4 py-3">Issue date</th>
                            <th className="px-4 py-3">Due date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-mono">{row.invoice_number}</td>
                                <td className="px-4 py-3 text-slate-400">{row.customer?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400 capitalize">{row.status.replace(/_/g, ' ')}</td>
                                <td className="px-4 py-3 text-slate-400">{row.currency} {row.total}</td>
                                <td className="px-4 py-3 text-slate-400">{row.amount_paid}</td>
                                <td className="px-4 py-3 text-slate-400">{row.balance_due}</td>
                                <td className="px-4 py-3 text-slate-400">{row.issue_date?.slice(0, 10) ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{row.due_date?.slice(0, 10) ?? '—'}</td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-500">No invoices in this range.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
