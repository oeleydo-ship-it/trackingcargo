import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import { statusBadgeClass, statusDotClass } from '../../../lib/statusColors';

interface StatusRow {
    id: number;
    code: string;
    name: string;
    color: string;
    role: string | null;
    role_label: string | null;
    /** Why this status cannot be deleted, when a module drives shipments into it. */
    system_use: string | null;
    sequence: number;
    is_public: boolean;
    is_terminal: boolean;
    is_initial: boolean;
    is_active: boolean;
    shipment_count: number;
    transitions_to: number[];
}

interface BranchOption {
    id: number;
    name: string;
    /** Has its own copy of the workflow rather than using the company default. */
    customised: boolean;
}

interface Props {
    statuses: StatusRow[];
    colors: string[];
    canManage: boolean;
    branches: BranchOption[];
    /** The branch being viewed; null for the company default. */
    selectedBranchId: number | null;
    /** A branch is selected but still uses the default, shown read-only. */
    inheritsDefault: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function ShipmentStatusesIndex({ statuses, colors, canManage, branches, selectedBranchId, inheritsDefault }: Props) {
    const [editing, setEditing] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);

    const selectedBranch = branches.find((branch) => branch.id === selectedBranchId) ?? null;
    const customisedCount = branches.filter((branch) => branch.customised).length;
    // A branch still on the default shows the default workflow, but editing it
    // from here would change every branch — so it is read-only until customised.
    const editable = canManage && !inheritsDefault;

    const selectBranch = (value: string) => {
        setEditing(null);
        setAdding(false);
        router.get('/settings/shipment-statuses', value ? { branch: value } : {}, { preserveScroll: true });
    };

    const customise = () => {
        if (selectedBranch) {
            router.post(`/settings/shipment-statuses/branches/${selectedBranch.id}/customise`, {}, { preserveScroll: true });
        }
    };

    const reset = () => {
        if (selectedBranch && confirm(`Discard ${selectedBranch.name}'s own workflow and use the company default again?`)) {
            router.delete(`/settings/shipment-statuses/branches/${selectedBranch.id}/customise`, { preserveScroll: true });
        }
    };

    return (
        <SettingsLayout title="Shipment statuses">
            <Head title="Shipment statuses" />

            <div className="mb-5 rounded-2xl border border-white/10 bg-white/[0.035] p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-64">
                        <label htmlFor="workflow-branch" className={labelClass}>Workflow for</label>
                        <select id="workflow-branch" value={selectedBranchId ?? ''} onChange={(event) => selectBranch(event.target.value)} className={fieldClass}>
                            <option value="">Company default</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name} {branch.customised ? '(customised)' : '(uses default)'}
                                </option>
                            ))}
                        </select>
                    </div>

                    {selectedBranch && canManage && (
                        inheritsDefault ? (
                            <button type="button" onClick={customise} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                                Customise for {selectedBranch.name}
                            </button>
                        ) : (
                            <button type="button" onClick={reset} className="rounded-lg border border-white/10 px-4 py-2 text-sm text-rose-300 transition hover:border-rose-400/40">
                                Reset to company default
                            </button>
                        )
                    )}
                </div>

                <p className="mt-3 text-sm text-slate-400">
                    {selectedBranch === null && (customisedCount === 0
                        ? 'Every branch uses this workflow.'
                        : `Used by every branch except the ${customisedCount} with ${customisedCount === 1 ? 'its' : 'their'} own workflow. Changes here do not affect customised branches.`)}
                    {selectedBranch !== null && inheritsDefault && `${selectedBranch.name} uses the company default workflow shown below. Customise it to give ${selectedBranch.name} its own statuses and flow, starting from a copy of the default.`}
                    {selectedBranch !== null && !inheritsDefault && `${selectedBranch.name} has its own workflow. Changes here affect only ${selectedBranch.name}'s shipments.`}
                </p>
            </div>

            <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-slate-400">
                    The workflow your shipments move through. Rename anything here and Operations follows immediately —
                    the flow arrows decide which moves a clerk is offered.
                </p>
                {editable && (
                    <button type="button" onClick={() => { setAdding((value) => !value); setEditing(null); }} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                        {adding ? 'Cancel' : 'Add status'}
                    </button>
                )}
            </div>

            {adding && <StatusForm statuses={statuses} colors={colors} branchId={selectedBranchId} onDone={() => setAdding(false)} />}

            <div className="space-y-3">
                {statuses.map((status) => (
                    <div key={status.id} className="rounded-2xl border border-white/10 bg-white/[0.035] p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs ${statusBadgeClass(status.color)}`}>
                                        <span className={`h-1.5 w-1.5 rounded-full ${statusDotClass(status.color)}`} />
                                        {status.name}
                                    </span>
                                    {status.is_initial && <Tag>Starting status</Tag>}
                                    {status.is_terminal && <Tag>Final</Tag>}
                                    {!status.is_active && <Tag>Switched off</Tag>}
                                    {!status.is_public && <Tag>Hidden from tracking</Tag>}
                                </div>
                                <p className="mt-2 font-mono text-xs text-slate-600">{status.code}</p>
                                {status.system_use && (
                                    <p className="mt-1 text-xs text-slate-500">Built in — {status.system_use}.</p>
                                )}
                                <p className="mt-1 text-xs text-slate-500">
                                    {status.shipment_count === 0
                                        ? 'No shipments here right now'
                                        : `${status.shipment_count} shipment${status.shipment_count === 1 ? '' : 's'} here right now`}
                                    {' · moves to '}
                                    {status.transitions_to.length === 0
                                        ? 'nowhere (dead end)'
                                        : status.transitions_to
                                            .map((id) => statuses.find((s) => s.id === id)?.name ?? '?')
                                            .join(', ')}
                                </p>
                            </div>

                            {editable && (
                                <div className="flex shrink-0 gap-3 text-xs">
                                    <button type="button" onClick={() => { setEditing(editing === status.id ? null : status.id); setAdding(false); }} className="text-cyan-300 hover:text-cyan-200">
                                        {editing === status.id ? 'Close' : 'Edit'}
                                    </button>
                                    {!status.system_use && !status.is_initial && status.shipment_count === 0 && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (confirm(`Remove "${status.name}"?`)) {
                                                    router.delete(`/settings/shipment-statuses/${status.id}`, { preserveScroll: true });
                                                }
                                            }}
                                            className="text-rose-400 hover:text-rose-300"
                                        >
                                            Remove
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>

                        {editing === status.id && (
                            <div className="mt-4 border-t border-white/5 pt-4">
                                <StatusForm status={status} statuses={statuses} colors={colors} branchId={selectedBranchId} onDone={() => setEditing(null)} />
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </SettingsLayout>
    );
}

function Tag({ children }: { children: React.ReactNode }) {
    return <span className="rounded-full border border-white/10 px-2 py-0.5 text-[10px] uppercase tracking-wider text-slate-500">{children}</span>;
}

interface StatusFormProps {
    status?: StatusRow;
    statuses: StatusRow[];
    colors: string[];
    /** Where a new status is added: this branch's own workflow, or the default when null. */
    branchId: number | null;
    onDone: () => void;
}

function StatusForm({ status, statuses, colors, branchId, onDone }: StatusFormProps) {
    const { data, setData, post, patch, processing, errors } = useForm({
        branch_id: branchId,
        name: status?.name ?? '',
        color: status?.color ?? 'slate',
        is_public: status?.is_public ?? true,
        is_terminal: status?.is_terminal ?? false,
        is_initial: status?.is_initial ?? false,
        is_active: status?.is_active ?? true,
        transitions_to: status?.transitions_to ?? [],
    });

    const toggleTarget = (id: number) =>
        setData('transitions_to', data.transitions_to.includes(id)
            ? data.transitions_to.filter((value) => value !== id)
            : [...data.transitions_to, id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (status) {
            patch(`/settings/shipment-statuses/${status.id}`, options);
        } else {
            post('/settings/shipment-statuses', options);
        }
    };

    const formErrors = errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className={status ? 'space-y-4' : 'mb-6 space-y-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6'}>
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`name-${status?.id ?? 'new'}`} className={labelClass}>Name</label>
                    <input id={`name-${status?.id ?? 'new'}`} value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Awaiting payment" />
                    {formErrors.name && <p className="mt-1 text-xs text-rose-400">{formErrors.name}</p>}
                </div>
                <div>
                    <label className={labelClass}>Colour</label>
                    <div className="flex flex-wrap gap-2 pt-1">
                        {colors.map((color) => (
                            <button
                                key={color}
                                type="button"
                                aria-label={color}
                                aria-pressed={data.color === color}
                                onClick={() => setData('color', color)}
                                className={`h-7 w-7 rounded-full ${statusDotClass(color)} ${data.color === color ? 'ring-2 ring-white ring-offset-2 ring-offset-slate-950' : 'opacity-60 hover:opacity-100'}`}
                            />
                        ))}
                    </div>
                    {formErrors.color && <p className="mt-1 text-xs text-rose-400">{formErrors.color}</p>}
                </div>
            </div>

            <div className="grid gap-2 sm:grid-cols-2">
                <Checkbox label="Show on the public tracking page" checked={data.is_public} onChange={(value) => setData('is_public', value)} />
                <Checkbox label="Final — shipments stop here" checked={data.is_terminal} onChange={(value) => setData('is_terminal', value)} />
                <Checkbox label="New shipments start here" checked={data.is_initial} onChange={(value) => setData('is_initial', value)} />
                <Checkbox label="Available to pick in Operations" checked={data.is_active} onChange={(value) => setData('is_active', value)} />
            </div>
            {formErrors.is_active && <p className="text-xs text-rose-400">{formErrors.is_active}</p>}
            {formErrors.is_initial && <p className="text-xs text-rose-400">{formErrors.is_initial}</p>}
            {formErrors.status && <p className="text-xs text-rose-400">{formErrors.status}</p>}

            <div>
                <p className={labelClass}>A shipment here can move to</p>
                <div className="flex flex-wrap gap-2">
                    {statuses.filter((candidate) => candidate.id !== status?.id).map((candidate) => {
                        const selected = data.transitions_to.includes(candidate.id);

                        return (
                            <button
                                key={candidate.id}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => toggleTarget(candidate.id)}
                                // A status's colour alone cannot show "selected": the default grey
                                // badge looks almost the same as an unselected chip. So a selected chip
                                // also gets a tick, a fill and a ring, whatever its colour.
                                className={`rounded-full border px-3 py-1 text-xs transition ${selected ? `${statusBadgeClass(candidate.color)} bg-white/10 font-semibold ring-1 ring-current` : 'border-white/10 text-slate-500 hover:text-slate-300'}`}
                            >
                                {selected && <span aria-hidden="true">✓ </span>}
                                {candidate.name}
                            </button>
                        );
                    })}
                </div>
                <p className="mt-2 text-xs text-slate-600">Nothing selected makes this a dead end — a shipment that arrives here cannot be moved on.</p>
            </div>

            <div className="flex gap-3">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : status ? 'Save changes' : 'Add status'}
                </button>
                <button type="button" onClick={onDone} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-400 hover:text-slate-200">Cancel</button>
            </div>
        </form>
    );
}

function Checkbox({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
    return (
        <label className="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="rounded border-white/20 bg-white/5" />
            {label}
        </label>
    );
}
