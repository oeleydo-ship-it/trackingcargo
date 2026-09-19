import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import BatchFilterBar, { activeBatchFilters, hasActiveBatchFilters } from '../../components/BatchFilterBar';
import type { BatchFilterOptions, BatchFilters, BranchOption, Paginated, ShipmentBatch } from '../../types';

interface BatchesIndexProps {
    batches: Paginated<ShipmentBatch>;
    filters: BatchFilters;
    filterOptions: BatchFilterOptions;
    branches: BranchOption[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    open: 'text-emerald-300 border-emerald-400/30',
    closed: 'text-slate-400 border-white/10',
};

export default function BatchesIndex({ batches, filters, filterOptions, branches }: BatchesIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const filtering = hasActiveBatchFilters(filters);
    // A page link has to carry the search and filters, or paging would quietly drop them.
    const goToPage = (page: number) => router.get('/batches', { ...activeBatchFilters(filters), page }, { preserveState: true });
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: (branches.length === 1 ? branches[0].id : '') as number | '',
        reference: '',
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/batches', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    return (
        <AppLayout title="Batches">
            <Head title="Batches" />

            <div className="mb-5 flex items-center justify-between">
                <div>
                    <p className="text-sm text-slate-400">{batches.total} batch{batches.total === 1 ? '' : 'es'}{filtering ? ' match your search' : ''}</p>
                    <p className="mt-0.5 text-xs text-slate-600">Group shipments to move them through a status change together.</p>
                </div>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New batch'}
                </button>
            </div>

            <BatchFilterBar filters={filters} options={filterOptions} />

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2">
                    <div>
                        <label htmlFor="branch_id" className={labelClass}>Branch</label>
                        <select id="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass}>
                            <option value="">Select a branch…</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>{branch.name} ({branch.code})</option>
                            ))}
                        </select>
                        {errors.branch_id && <p className="mt-1 text-xs text-rose-400">{errors.branch_id}</p>}
                        <p className="mt-1 text-xs text-slate-600">A batch may only hold shipments from its own branch.</p>
                    </div>
                    <div>
                        <label htmlFor="reference" className={labelClass}>Reference (optional)</label>
                        <input id="reference" value={data.reference} onChange={(event) => setData('reference', event.target.value)} className={fieldClass} placeholder="Tuesday air consolidation" />
                        {errors.reference && <p className="mt-1 text-xs text-rose-400">{errors.reference}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="notes" className={labelClass}>Notes (optional)</label>
                        <textarea id="notes" value={data.notes} onChange={(event) => setData('notes', event.target.value)} rows={2} className={fieldClass} />
                        {errors.notes && <p className="mt-1 text-xs text-rose-400">{errors.notes}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create batch'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Batch #</th>
                            <th className="px-4 py-3">Reference</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Shipments</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {batches.data.map((batch) => (
                            <tr key={batch.id}>
                                <td className="px-4 py-3 font-mono text-xs">
                                    <Link href={`/batches/${batch.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">{batch.batch_number}</Link>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{batch.reference ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{batch.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{batch.shipments_count ?? 0}</td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[batch.status]}`}>{batch.status}</span></td>
                            </tr>
                        ))}
                        {batches.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{filtering ? 'No batches match your search or filters.' : 'No batches yet.'}</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {batches.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>{batches.from ?? 0}–{batches.to ?? 0} of {batches.total}</span>
                    <div className="flex gap-2">
                        <button disabled={batches.current_page <= 1} onClick={() => goToPage(batches.current_page - 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Previous</button>
                        <button disabled={batches.current_page >= batches.last_page} onClick={() => goToPage(batches.current_page + 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Next</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
