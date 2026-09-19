import type { FormEvent } from 'react';
import { activeFilters, hasActiveFilters, useListFilters } from '../lib/listFilters';
import type { BatchFilterOptions, BatchFilters } from '../types';
import FilterSelect from './FilterSelect';

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export const emptyBatchFilters: BatchFilters = { q: '', status: '', branch_id: '', from: '', to: '' };

export const activeBatchFilters = (filters: BatchFilters) => activeFilters({ ...filters });
export const hasActiveBatchFilters = (filters: BatchFilters) => hasActiveFilters({ ...filters });

const statuses = [
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
];

interface BatchFilterBarProps {
    /** What the server is currently applying. */
    filters: BatchFilters;
    options: BatchFilterOptions;
}

/** Search box and filters for the batches list. Applied with the Search button, or Enter in the search box. */
export default function BatchFilterBar({ filters, options }: BatchFilterBarProps) {
    const { values, set, search, clear } = useListFilters('/batches', filters, emptyBatchFilters, ['batches', 'filters']);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        search();
    };

    const branchChoices = options.branches.map((branch) => ({ value: String(branch.id), label: branch.name }));

    return (
        <form onSubmit={submit} role="search" aria-label="Search and filter batches" className="mb-5 rounded-2xl border border-white/10 bg-white/[0.035] p-4">
            <div className="w-full sm:w-[30%] sm:min-w-64">
                <label htmlFor="batch-search" className={labelClass}>Search</label>
                <input
                    id="batch-search"
                    type="search"
                    value={values.q}
                    onChange={(event) => set({ q: event.target.value })}
                    placeholder="Batch #, reference, tracking #…"
                    title="Searches batch number, reference, notes, who opened it, and the tracking number of a shipment inside it"
                    maxLength={100}
                    className={fieldClass}
                />
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect id="batch-filter-status" label="Status" value={values.status} allLabel="All statuses" choices={statuses} onChange={(status) => set({ status })} />
                {branchChoices.length > 1 || values.branch_id !== '' ? (
                    <FilterSelect id="batch-filter-branch" label="Branch" value={values.branch_id} allLabel="All branches" choices={branchChoices} onChange={(branch_id) => set({ branch_id })} />
                ) : null}
                <div>
                    <label htmlFor="batch-filter-from" className={labelClass}>Opened from</label>
                    <input id="batch-filter-from" type="date" value={values.from} max={values.to || undefined} onChange={(event) => set({ from: event.target.value })} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="batch-filter-to" className={labelClass}>Opened to</label>
                    <input id="batch-filter-to" type="date" value={values.to} min={values.from || undefined} onChange={(event) => set({ to: event.target.value })} className={fieldClass} />
                </div>
                <div className="flex items-end gap-2">
                    <button type="submit" className="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                        Search
                    </button>
                    {(hasActiveBatchFilters(values) || hasActiveBatchFilters(filters)) && (
                        <button type="button" onClick={clear} className="rounded-xl border border-white/10 px-4 py-2.5 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                            Clear
                        </button>
                    )}
                </div>
            </div>
        </form>
    );
}
