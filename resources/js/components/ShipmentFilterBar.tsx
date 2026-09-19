import type { FormEvent } from 'react';
import { activeFilters, hasActiveFilters, useListFilters } from '../lib/listFilters';
import type { ShipmentFilterOptions, ShipmentFilters } from '../types';
import FilterSelect from './FilterSelect';

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const regionNames = new Intl.DisplayNames(['en'], { type: 'region' });

export const emptyShipmentFilters: ShipmentFilters = { q: '', status: '', mode: '', branch_id: '', carrier_id: '', country: '', from: '', to: '' };

export const activeShipmentFilters = (filters: ShipmentFilters) => activeFilters({ ...filters });
export const hasActiveShipmentFilters = (filters: ShipmentFilters) => hasActiveFilters({ ...filters });

const modes = [
    { value: 'air', label: 'Air' },
    { value: 'sea', label: 'Sea' },
    { value: 'road', label: 'Road' },
    { value: 'courier', label: 'Courier' },
];

interface ShipmentFilterBarProps {
    /** What the server is currently applying. */
    filters: ShipmentFilters;
    options: ShipmentFilterOptions;
}

/**
 * Search box and filters for the shipments list (Operations). Applied with the
 * Search button, or Enter in the search box.
 */
export default function ShipmentFilterBar({ filters, options }: ShipmentFilterBarProps) {
    const { values, set, search, clear } = useListFilters('/shipments', filters, emptyShipmentFilters, ['shipments', 'filters']);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        search();
    };

    const statusChoices = options.statuses.map((status) => ({ value: status.code, label: status.name }));
    const branchChoices = options.branches.map((branch) => ({ value: String(branch.id), label: branch.name }));
    const carrierChoices = options.carriers.map((carrier) => ({ value: String(carrier.id), label: carrier.name }));
    const countryChoices = options.countries.map((code) => ({ value: code, label: `${regionNames.of(code) ?? code} (${code})` }));

    return (
        <form onSubmit={submit} role="search" aria-label="Search and filter shipments" className="mb-5 rounded-2xl border border-white/10 bg-white/[0.035] p-4">
            <div className="w-full sm:w-[30%] sm:min-w-64">
                <label htmlFor="shipment-search" className={labelClass}>Search</label>
                <input
                    id="shipment-search"
                    type="search"
                    value={values.q}
                    onChange={(event) => set({ q: event.target.value })}
                    placeholder="Tracking #, name, customer, city…"
                    title="Searches tracking number, consignor or consignee, customer, destination city, batch, carrier and package barcode"
                    maxLength={100}
                    className={fieldClass}
                />
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect id="filter-status" label="Status" value={values.status} allLabel="All statuses" choices={statusChoices} onChange={(status) => set({ status })} />
                <FilterSelect id="filter-mode" label="Mode" value={values.mode} allLabel="All modes" choices={modes} onChange={(mode) => set({ mode })} />
                {branchChoices.length > 1 || values.branch_id !== '' ? (
                    <FilterSelect id="filter-branch" label="Branch" value={values.branch_id} allLabel="All branches" choices={branchChoices} onChange={(branch_id) => set({ branch_id })} />
                ) : null}
                {carrierChoices.length > 0 || values.carrier_id !== '' ? (
                    <FilterSelect id="filter-carrier" label="Carrier" value={values.carrier_id} allLabel="All carriers" choices={carrierChoices} onChange={(carrier_id) => set({ carrier_id })} />
                ) : null}
                {countryChoices.length > 0 || values.country !== '' ? (
                    <FilterSelect id="filter-country" label="Destination country" value={values.country} allLabel="All countries" choices={countryChoices} onChange={(country) => set({ country })} />
                ) : null}
                <div>
                    <label htmlFor="filter-from" className={labelClass}>Created from</label>
                    <input id="filter-from" type="date" value={values.from} max={values.to || undefined} onChange={(event) => set({ from: event.target.value })} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="filter-to" className={labelClass}>Created to</label>
                    <input id="filter-to" type="date" value={values.to} min={values.from || undefined} onChange={(event) => set({ to: event.target.value })} className={fieldClass} />
                </div>
                <div className="flex items-end gap-2">
                    <button type="submit" className="rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                        Search
                    </button>
                    {(hasActiveShipmentFilters(values) || hasActiveShipmentFilters(filters)) && (
                        <button type="button" onClick={clear} className="rounded-xl border border-white/10 px-4 py-2.5 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                            Clear
                        </button>
                    )}
                </div>
            </div>
        </form>
    );
}
