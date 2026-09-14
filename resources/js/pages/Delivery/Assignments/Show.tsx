import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SignaturePad from '../../../components/SignaturePad';
import AppLayout from '../../../layouts/AppLayout';
import type { DeliveryAssignment, DeliveryAttemptOutcome } from '../../../types';

interface ShowProps {
    shipment: { id: number; tracking_number: string; status: string; cod_amount: string | null; currency: string };
    assignment: DeliveryAssignment;
    allowedTransitions: { value: string; label: string }[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    assigned: 'text-slate-400 border-white/10',
    out_for_delivery: 'text-blue-300 border-blue-400/30',
    delivered: 'text-emerald-300 border-emerald-400/30',
    cancelled: 'text-rose-300 border-rose-400/30',
};

function newIdempotencyKey(): string {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function Show({ shipment, assignment, allowedTransitions }: ShowProps) {
    return (
        <AppLayout title={`Delivery · ${shipment.tracking_number}`}>
            <Head title="Delivery assignment" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link href={`/shipments/${shipment.id}`} className="font-mono text-xs text-cyan-300 hover:text-cyan-200">{shipment.tracking_number}</Link>
                    <h2 className="text-xl font-semibold">Delivery to {assignment.driver.user.name}</h2>
                </div>
                <span className={`rounded-full border px-3 py-1 text-xs capitalize ${statusColor[assignment.status] ?? 'border-white/10 text-slate-400'}`}>{assignment.status.replace(/_/g, ' ')}</span>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <SummaryCard assignment={assignment} />
                    {assignment.status === 'out_for_delivery' && (
                        <RecordAttemptCard shipment={shipment} assignment={assignment} />
                    )}
                    <AttemptsCard shipmentId={shipment.id} assignment={assignment} />
                </div>
                <TransitionCard shipmentId={shipment.id} assignment={assignment} allowedTransitions={allowedTransitions} />
            </div>
        </AppLayout>
    );
}

function SummaryCard({ assignment }: { assignment: DeliveryAssignment }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Summary</p>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-slate-500">Driver</dt><dd>{assignment.driver.user.name}</dd></div>
                <div><dt className="text-xs text-slate-500">Vehicle</dt><dd>{assignment.vehicle ? `${assignment.vehicle.registration_number}` : '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Zone</dt><dd>{assignment.zone?.name ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Scheduled</dt><dd>{assignment.scheduled_date ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Delivered</dt><dd>{assignment.delivered_at ? new Date(assignment.delivered_at).toLocaleString() : '—'}</dd></div>
            </dl>
            {assignment.notes && <p className="mt-4 text-sm text-slate-400">{assignment.notes}</p>}
        </article>
    );
}

function TransitionCard({ shipmentId, assignment, allowedTransitions }: { shipmentId: number; assignment: DeliveryAssignment; allowedTransitions: { value: string; label: string }[] }) {
    const { data, setData, post, processing, errors } = useForm({ status: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipmentId}/delivery-assignments/${assignment.id}/transitions`);
    };

    if (allowedTransitions.length === 0) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <p className="text-sm font-semibold">Status</p>
                <p className="mt-3 text-sm text-slate-500">This delivery is in a terminal state.</p>
            </article>
        );
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Update status</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <select value={data.status} onChange={(event) => setData('status', event.target.value)} required className={fieldClass}>
                    <option value="" disabled>Select a status…</option>
                    {allowedTransitions.map((transition) => (
                        <option key={transition.value} value={transition.value}>{transition.label}</option>
                    ))}
                </select>
                {errors.status && <p className="text-xs text-rose-400">{errors.status}</p>}
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Apply</button>
            </form>
        </article>
    );
}

function RecordAttemptCard({ shipment, assignment }: { shipment: ShowProps['shipment']; assignment: DeliveryAssignment }) {
    const { data, setData, post, processing, errors, reset, setError, clearErrors } = useForm<{
        idempotency_key: string;
        outcome: DeliveryAttemptOutcome;
        recipient_name: string;
        collected_amount: number | '';
        signature: File | null;
        photos: File[];
        failure_reason: string;
        reschedule_date: string;
        notes: string;
    }>({
        idempotency_key: newIdempotencyKey(),
        outcome: 'succeeded',
        recipient_name: '',
        collected_amount: shipment.cod_amount ? Number(shipment.cod_amount) : '',
        signature: null,
        photos: [],
        failure_reason: '',
        reschedule_date: '',
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (data.outcome === 'succeeded' && !data.signature) {
            setError('signature', 'A signature is required to record a successful delivery.');
            return;
        }
        clearErrors('signature');

        post(`/shipments/${shipment.id}/delivery-assignments/${assignment.id}/attempts`, {
            forceFormData: true,
            onSuccess: () => {
                reset('recipient_name', 'collected_amount', 'signature', 'photos', 'failure_reason', 'reschedule_date', 'notes');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Record delivery attempt</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label className={labelClass}>Outcome</label>
                    <select value={data.outcome} onChange={(event) => setData('outcome', event.target.value as DeliveryAttemptOutcome)} className={fieldClass}>
                        <option value="succeeded">Succeeded</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>

                {data.outcome === 'succeeded' ? (
                    <>
                        <div>
                            <label className={labelClass}>Recipient name</label>
                            <input value={data.recipient_name} onChange={(event) => setData('recipient_name', event.target.value)} required className={fieldClass} />
                            {errors.recipient_name && <p className="mt-1 text-xs text-rose-400">{errors.recipient_name}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Signature</label>
                            <SignaturePad onChange={(file) => setData('signature', file)} />
                            {errors.signature && <p className="mt-1 text-xs text-rose-400">{errors.signature}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Photo (optional)</label>
                            <input type="file" accept="image/*" onChange={(event) => setData('photos', event.target.files ? [event.target.files[0]] : [])} className={fieldClass} />
                        </div>
                        {shipment.cod_amount && (
                            <div>
                                <label className={labelClass}>Collected amount ({shipment.currency})</label>
                                <input type="number" step="0.01" min="0" value={data.collected_amount} onChange={(event) => setData('collected_amount', event.target.value ? Number(event.target.value) : '')} className={fieldClass} />
                                {errors.collected_amount && <p className="mt-1 text-xs text-rose-400">{errors.collected_amount}</p>}
                                <p className="mt-1 text-xs text-slate-500">COD due for this shipment: {shipment.cod_amount} {shipment.currency}</p>
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        <div>
                            <label className={labelClass}>Failure reason</label>
                            <input value={data.failure_reason} onChange={(event) => setData('failure_reason', event.target.value)} required className={fieldClass} placeholder="Recipient not home" />
                            {errors.failure_reason && <p className="mt-1 text-xs text-rose-400">{errors.failure_reason}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Reschedule date (optional)</label>
                            <input type="date" value={data.reschedule_date} onChange={(event) => setData('reschedule_date', event.target.value)} className={fieldClass} />
                            {errors.reschedule_date && <p className="mt-1 text-xs text-rose-400">{errors.reschedule_date}</p>}
                        </div>
                    </>
                )}

                <textarea placeholder="Notes (optional)" rows={2} value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={fieldClass} />

                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Submitting…' : 'Submit attempt'}
                </button>
            </form>
        </article>
    );
}

function AttemptsCard({ shipmentId, assignment }: { shipmentId: number; assignment: DeliveryAssignment }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Attempts</p>
            <div className="mt-4 space-y-2">
                {assignment.attempts.map((attempt) => (
                    <div key={attempt.id} className="rounded-xl border border-white/5 p-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className={`rounded-full border px-2 py-0.5 text-xs ${attempt.outcome === 'succeeded' ? 'border-emerald-400/30 text-emerald-300' : 'border-rose-400/30 text-rose-300'}`}>{attempt.outcome}</span>
                            <span className="text-xs text-slate-500">{new Date(attempt.attempted_at).toLocaleString()}</span>
                        </div>
                        <p className="mt-1 text-xs text-slate-400">
                            {attempt.recipient_name && `Recipient: ${attempt.recipient_name}`}
                            {attempt.failure_reason && `Reason: ${attempt.failure_reason}`}
                            {attempt.reschedule_date && ` · Reschedule: ${attempt.reschedule_date}`}
                        </p>
                        {attempt.outcome === 'succeeded' && (
                            <a
                                href={`/shipments/${shipmentId}/delivery-assignments/${assignment.id}/attempts/${attempt.id}/pod.pdf`}
                                target="_blank"
                                rel="noreferrer"
                                className="mt-2 inline-block text-xs text-cyan-300 hover:text-cyan-200"
                            >
                                View POD ↗
                            </a>
                        )}
                    </div>
                ))}
                {assignment.attempts.length === 0 && <p className="text-sm text-slate-500">No attempts recorded yet.</p>}
            </div>
        </article>
    );
}
