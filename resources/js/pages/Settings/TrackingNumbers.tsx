import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SettingsLayout from '../../layouts/SettingsLayout';
import { renderTrackingNumber, trackingFormatProblem } from '../../lib/trackingNumber';
import type { Company } from '../../types';

interface TrackingNumbersPageProps {
    company: Company | null;
    tokens: Record<string, string>;
    sampleBranchPrefix: string | null;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-2 block text-sm font-medium text-slate-300';

export default function TrackingNumbers({ company, tokens, sampleBranchPrefix }: TrackingNumbersPageProps) {
    return (
        <SettingsLayout title="Tracking numbers">
            <Head title="Tracking number settings" />

            {company ? (
                <TrackingNumberForm company={company} tokens={tokens} sampleBranchPrefix={sampleBranchPrefix} />
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
                    onChange={(event) => setData('allow_manual_tracking_number', event.target.checked)}
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

            <button type="submit" disabled={processing || problem !== null} className="rounded-xl bg-cyan-400 px-5 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : 'Save tracking settings'}
            </button>
        </form>
    );
}
