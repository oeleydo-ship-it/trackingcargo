import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../layouts/SettingsLayout';
import { renderTrackingNumber, trackingFormatProblem } from '../../lib/trackingNumber';
import type { Company, ShipmentMode, TrackingNumberMode } from '../../types';

interface FormatRow {
    id: number;
    branch_id: number | null;
    branch_name: string | null;
    mode: ShipmentMode | null;
    format: string;
    sequence_padding: number;
}

interface BranchRow {
    id: number;
    name: string;
    tracking_prefix: string;
    /** Null follows the company's default. */
    default_tracking_mode: TrackingNumberMode | null;
}

const methodLabels: Record<TrackingNumberMode, string> = {
    auto: 'Generate automatically',
    suffix: 'Company / branch + receipt reference',
    full: 'Enter entire tracking number',
};

interface TrackingNumbersPageProps {
    company: Company | null;
    tokens: Record<string, string>;
    sampleBranchPrefix: string | null;
    branches: BranchRow[];
    modes: ShipmentMode[];
    formats: FormatRow[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-2 block text-sm font-medium text-slate-300';

export default function TrackingNumbers({ company, tokens, sampleBranchPrefix, branches, modes, formats }: TrackingNumbersPageProps) {
    return (
        <SettingsLayout title="Tracking numbers">
            <Head title="Tracking number settings" />

            {company ? (
                <>
                    <TrackingNumberForm company={company} tokens={tokens} sampleBranchPrefix={sampleBranchPrefix} />
                    <BranchMethods company={company} branches={branches} />
                    <FormatRules company={company} branches={branches} modes={modes} formats={formats} tokens={tokens} />
                </>
            ) : (
                <div className="max-w-2xl rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-slate-500">
                    No company selected. Choose one on the Company tab to edit its tracking numbers.
                </div>
            )}
        </SettingsLayout>
    );
}

function TrackingNumberForm({ company, tokens, sampleBranchPrefix }: { company: Company; tokens: Record<string, string>; sampleBranchPrefix: string | null }) {
    const { data, setData, patch, processing, errors } = useForm({
        tracking_number_format: company.tracking_number_format,
        tracking_sequence_padding: company.tracking_sequence_padding,
        allow_manual_tracking_number: company.allow_manual_tracking_number,
        default_tracking_mode: (company.default_tracking_mode ?? 'auto') as TrackingNumberMode,
    });

    const branchPrefix = sampleBranchPrefix ?? 'HQ';
    const problem = trackingFormatProblem(data.tracking_number_format);

    const preview = renderTrackingNumber({
        format: data.tracking_number_format,
        companyCode: company.code,
        branchPrefix,
        padding: Number(data.tracking_sequence_padding) || 1,
        sequence: 1,
    });

    const insertToken = (token: string) => setData('tracking_number_format', `${data.tracking_number_format}${token}`);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/tracking');
    };

    return (
        <form onSubmit={submit} className="max-w-2xl space-y-5 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm text-slate-400">
                Every shipment booked in Operations gets its tracking number from this pattern. The running number is allocated per branch.
            </p>

            <div>
                <label htmlFor="tracking_number_format" className={labelClass}>Format</label>
                <input
                    id="tracking_number_format"
                    value={data.tracking_number_format}
                    onChange={(event) => setData('tracking_number_format', event.target.value)}
                    required
                    className={`${fieldClass} font-mono`}
                />
                {errors.tracking_number_format
                    ? <p className="mt-2 text-sm text-rose-400">{errors.tracking_number_format}</p>
                    : problem && <p className="mt-2 text-sm text-amber-400">{problem}</p>}

                <div className="mt-3 flex flex-wrap gap-2">
                    {Object.entries(tokens).map(([token, description]) => (
                        <button
                            key={token}
                            type="button"
                            title={description}
                            onClick={() => insertToken(token)}
                            className="rounded-lg border border-white/10 px-2 py-1 font-mono text-xs text-slate-400 transition hover:border-cyan-400/50 hover:text-cyan-300"
                        >
                            {token}
                        </button>
                    ))}
                </div>
                <p className="mt-2 text-xs text-slate-600">
                    Including <span className="font-mono">{'{month}'}</span> or <span className="font-mono">{'{year}'}</span> restarts the running number each month or year.
                </p>
            </div>

            <div className="grid gap-5 sm:grid-cols-2">
                <div>
                    <label htmlFor="tracking_sequence_padding" className={labelClass}>Sequence digits</label>
                    <input
                        id="tracking_sequence_padding"
                        type="number"
                        min={1}
                        max={12}
                        value={data.tracking_sequence_padding}
                        onChange={(event) => setData('tracking_sequence_padding', Number(event.target.value))}
                        required
                        className={fieldClass}
                    />
                    {errors.tracking_sequence_padding && <p className="mt-2 text-sm text-rose-400">{errors.tracking_sequence_padding}</p>}
                </div>
                <div>
                    <p className={labelClass}>Preview</p>
                    <p className={`rounded-xl border px-4 py-3 font-mono break-all ${problem ? 'border-amber-400/30 bg-amber-400/5 text-amber-200' : 'border-cyan-400/20 bg-cyan-400/5 text-cyan-200'}`}>{preview}</p>
                    <p className="mt-1.5 text-xs text-slate-600">Using branch prefix {branchPrefix}.</p>
                </div>
            </div>

            <label className="flex items-start gap-3 rounded-xl border border-white/10 p-4 text-sm text-slate-300">
                <input
                    type="checkbox"
                    checked={data.allow_manual_tracking_number}
                    onChange={(event) => setData((current) => ({
                        ...current,
                        allow_manual_tracking_number: event.target.checked,
                        default_tracking_mode: !event.target.checked && current.default_tracking_mode === 'full' ? 'auto' : current.default_tracking_mode,
                    }))}
                    className="mt-0.5 size-4 rounded border-white/20 bg-white/5 text-cyan-400"
                />
                <span>
                    Allow manual tracking numbers
                    <span className="mt-0.5 block text-xs text-slate-500">
                        Staff can override the generated number when booking, and rename it while the shipment is still a draft.
                    </span>
                </span>
            </label>
            {errors.allow_manual_tracking_number && <p className="text-sm text-rose-400">{errors.allow_manual_tracking_number}</p>}

            <div>
                <label htmlFor="default_tracking_mode" className={labelClass}>Default numbering method</label>
                <select id="default_tracking_mode" value={data.default_tracking_mode} onChange={(event) => setData('default_tracking_mode', event.target.value as TrackingNumberMode)} className={fieldClass}>
                    <option value="auto">{methodLabels.auto}</option>
                    <option value="suffix">{methodLabels.suffix}</option>
                    <option value="full" disabled={!data.allow_manual_tracking_number}>{methodLabels.full}{data.allow_manual_tracking_number ? '' : ' (turn on manual tracking numbers first)'}</option>
                </select>
                <p className="mt-1.5 text-xs text-slate-500">
                    What the booking form starts with. "Receipt reference" keeps the company / branch prefix and lets staff type the receipt or reference number in place of the running number. Staff can still pick another method for any one shipment, and each branch can have its own default below.
                </p>
                {errors.default_tracking_mode && <p className="mt-1.5 text-sm text-rose-400">{errors.default_tracking_mode}</p>}
            </div>

            <button type="submit" disabled={processing || problem !== null} className="rounded-xl bg-cyan-400 px-5 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : 'Save tracking settings'}
            </button>
        </form>
    );
}

/**
 * Each branch's own starting numbering method. Blank follows the company's, so
 * a branch that books from a paper receipt book can start on "receipt
 * reference" while the others keep generating numbers.
 */
function BranchMethods({ company, branches }: { company: Company; branches: BranchRow[] }) {
    const [error, setError] = useState<string | null>(null);

    const change = (branch: BranchRow, value: string) => {
        setError(null);
        router.patch(`/settings/tracking/branches/${branch.id}`, { default_tracking_mode: value === '' ? null : value }, {
            preserveScroll: true,
            onError: (errors) => setError(errors.default_tracking_mode ?? 'Could not save that change.'),
        });
    };

    return (
        <section className="mt-8 max-w-2xl rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <h3 className="text-sm font-semibold">Default numbering method by branch</h3>
            <p className="mt-1 text-xs text-slate-500">
                A branch starts on the company default ({methodLabels[company.default_tracking_mode ?? 'auto']}) unless you choose its own here. Saved as soon as you change it.
            </p>
            {error && <p className="mt-3 text-sm text-rose-400">{error}</p>}
            <div className="mt-4 divide-y divide-white/5">
                {branches.map((branch) => (
                    <div key={branch.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <label htmlFor={`branch-method-${branch.id}`} className="text-sm text-slate-300">
                            {branch.name} <span className="font-mono text-xs text-slate-500">{branch.tracking_prefix}</span>
                        </label>
                        <select
                            id={`branch-method-${branch.id}`}
                            value={branch.default_tracking_mode ?? ''}
                            onChange={(event) => change(branch, event.target.value)}
                            className="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400 sm:w-72"
                        >
                            <option value="">Same as company</option>
                            <option value="auto">{methodLabels.auto}</option>
                            <option value="suffix">{methodLabels.suffix}</option>
                            <option value="full" disabled={!company.allow_manual_tracking_number}>{methodLabels.full}</option>
                        </select>
                    </div>
                ))}
                {branches.length === 0 && <p className="py-3 text-sm text-slate-500">No branches yet.</p>}
            </div>
        </section>
    );
}

/**
 * Formats that override the default pattern for one branch, one mode, or one
 * exact combination. The most specific match wins, so a Dubai + air rule beats
 * an air rule, which beats the default above.
 */
function FormatRules({ company, branches, modes, formats, tokens }: { company: Company; branches: BranchRow[]; modes: ShipmentMode[]; formats: FormatRow[]; tokens: Record<string, string> }) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);

    const remove = (rule: FormatRow) => {
        if (confirm(`Remove the ${describe(rule)} format? New bookings fall back to the next matching format.`)) {
            router.delete(`/settings/tracking/formats/${rule.id}`, { preserveScroll: true });
        }
    };

    return (
        <section className="mt-6 max-w-3xl rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-xl">
                    <h2 className="text-sm font-semibold text-white">Formats by branch and mode</h2>
                    <p className="mt-1 text-sm text-slate-400">
                        Use these when a branch or a mode needs its own pattern — sea and air, say. Each format keeps its
                        own running number, so both start at 1. Anything not covered here uses the pattern above.
                    </p>
                </div>
                <button type="button" onClick={() => { setAdding((value) => !value); setEditing(null); }} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {adding ? 'Cancel' : 'Add format'}
                </button>
            </div>

            {adding && <RuleForm company={company} branches={branches} modes={modes} tokens={tokens} onDone={() => setAdding(false)} />}

            <div className="mt-4 space-y-3">
                {formats.map((rule) => (
                    <div key={rule.id} className="rounded-xl border border-white/10 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-medium text-white">{describe(rule)}</p>
                                <p className="mt-1 font-mono text-xs text-cyan-200">
                                    {renderTrackingNumber({
                                        format: rule.format,
                                        companyCode: company.code,
                                        branchPrefix: branches.find((branch) => branch.id === rule.branch_id)?.tracking_prefix ?? branches[0]?.tracking_prefix ?? 'HQ',
                                        padding: rule.sequence_padding,
                                        sequence: 1,
                                    })}
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-3 text-xs">
                                <button type="button" onClick={() => { setEditing(editing === rule.id ? null : rule.id); setAdding(false); }} className="text-cyan-300 hover:text-cyan-200">
                                    {editing === rule.id ? 'Close' : 'Edit'}
                                </button>
                                <button type="button" onClick={() => remove(rule)} className="text-rose-400 hover:text-rose-300">Remove</button>
                            </div>
                        </div>

                        {editing === rule.id && (
                            <div className="mt-4 border-t border-white/5 pt-4">
                                <RuleForm company={company} branches={branches} modes={modes} tokens={tokens} rule={rule} onDone={() => setEditing(null)} />
                            </div>
                        )}
                    </div>
                ))}

                {formats.length === 0 && !adding && (
                    <p className="rounded-xl border border-dashed border-white/10 p-6 text-center text-sm text-slate-500">
                        No overrides — every branch and mode uses the pattern above.
                    </p>
                )}
            </div>
        </section>
    );
}

function describe(rule: { branch_name: string | null; mode: ShipmentMode | null }): string {
    return `${rule.branch_name ?? 'All branches'} · ${rule.mode ? rule.mode.charAt(0).toUpperCase() + rule.mode.slice(1) : 'All modes'}`;
}

function RuleForm({ company, branches, modes, tokens, rule, onDone }: { company: Company; branches: BranchRow[]; modes: ShipmentMode[]; tokens: Record<string, string>; rule?: FormatRow; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        branch_id: (rule?.branch_id ?? '') as number | '',
        mode: (rule?.mode ?? '') as ShipmentMode | '',
        format: rule?.format ?? '',
        sequence_padding: rule?.sequence_padding ?? company.tracking_sequence_padding,
    });

    const problem = data.format === '' ? null : trackingFormatProblem(data.format);
    const branchPrefix = branches.find((branch) => branch.id === data.branch_id)?.tracking_prefix ?? branches[0]?.tracking_prefix ?? 'HQ';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (rule) {
            patch(`/settings/tracking/formats/${rule.id}`, options);
        } else {
            post('/settings/tracking/formats', options);
        }
    };

    const fieldErrors = errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className={rule ? 'space-y-4' : 'mt-4 space-y-4 rounded-xl border border-cyan-400/30 bg-cyan-400/5 p-4'}>
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`rule-branch-${rule?.id ?? 'new'}`} className={labelClass}>Branch</label>
                    <select id={`rule-branch-${rule?.id ?? 'new'}`} value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass}>
                        <option value="">All branches</option>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor={`rule-mode-${rule?.id ?? 'new'}`} className={labelClass}>Mode</label>
                    <select id={`rule-mode-${rule?.id ?? 'new'}`} value={data.mode} onChange={(event) => setData('mode', event.target.value as ShipmentMode | '')} className={fieldClass}>
                        <option value="">All modes</option>
                        {modes.map((mode) => <option key={mode} value={mode}>{mode.charAt(0).toUpperCase() + mode.slice(1)}</option>)}
                    </select>
                </div>
            </div>

            <div>
                <label htmlFor={`rule-format-${rule?.id ?? 'new'}`} className={labelClass}>Format</label>
                <input id={`rule-format-${rule?.id ?? 'new'}`} value={data.format} onChange={(event) => setData('format', event.target.value.toUpperCase())} required className={`${fieldClass} font-mono`} placeholder="SGFS-CS{sequence}" />
                <div className="mt-2 flex flex-wrap gap-2">
                    {Object.keys(tokens).map((token) => (
                        <button key={token} type="button" onClick={() => setData('format', data.format + token)} className="rounded-lg border border-white/10 px-2 py-1 font-mono text-xs text-slate-300 hover:border-cyan-400/40">
                            {token}
                        </button>
                    ))}
                </div>
                {problem && <p className="mt-2 text-sm text-amber-300">{problem}</p>}
                {fieldErrors.format && <p className="mt-2 text-sm text-rose-400">{fieldErrors.format}</p>}
                {fieldErrors.combination && <p className="mt-2 text-sm text-rose-400">{fieldErrors.combination}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`rule-padding-${rule?.id ?? 'new'}`} className={labelClass}>Sequence digits</label>
                    <input id={`rule-padding-${rule?.id ?? 'new'}`} type="number" min={1} max={12} value={data.sequence_padding} onChange={(event) => setData('sequence_padding', Number(event.target.value))} className={fieldClass} />
                    {fieldErrors.sequence_padding && <p className="mt-2 text-sm text-rose-400">{fieldErrors.sequence_padding}</p>}
                </div>
                <div>
                    <span className={labelClass}>Preview</span>
                    <p className="rounded-xl border border-white/10 bg-slate-900 px-4 py-3 font-mono text-sm text-cyan-200">
                        {data.format === '' || problem
                            ? '—'
                            : renderTrackingNumber({ format: data.format, companyCode: company.code, branchPrefix, padding: data.sequence_padding, sequence: 1 })}
                    </p>
                </div>
            </div>

            <div className="flex gap-3">
                <button type="submit" disabled={processing || problem !== null} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : rule ? 'Save format' : 'Add format'}
                </button>
                <button type="button" onClick={onDone} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-400 hover:text-slate-200">Cancel</button>
            </div>
        </form>
    );
}
