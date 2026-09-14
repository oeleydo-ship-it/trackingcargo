import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { CustomsClearance, DocumentCategory, DutyType, InspectionType } from '../../types';

interface ShowProps {
    shipment: { id: number; tracking_number: string; status: string };
    clearance: CustomsClearance;
    allowedTransitions: { value: string; label: string }[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    pending: 'text-slate-400 border-white/10',
    under_review: 'text-blue-300 border-blue-400/30',
    cleared: 'text-emerald-300 border-emerald-400/30',
    held: 'text-amber-300 border-amber-400/30',
    rejected: 'text-rose-300 border-rose-400/30',
};

function newIdempotencyKey(): string {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function Show({ shipment, clearance, allowedTransitions }: ShowProps) {
    return (
        <AppLayout title={clearance.declaration_number ?? `Clearance #${clearance.id}`}>
            <Head title="Customs clearance" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link href={`/shipments/${shipment.id}`} className="font-mono text-xs text-cyan-300 hover:text-cyan-200">{shipment.tracking_number}</Link>
                    <h2 className="text-xl font-semibold">{clearance.declaration_number ?? `Clearance #${clearance.id}`}</h2>
                </div>
                <span className={`rounded-full border px-3 py-1 text-xs capitalize ${statusColor[clearance.status] ?? 'border-white/10 text-slate-400'}`}>{clearance.status.replace(/_/g, ' ')}</span>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <SummaryCard clearance={clearance} />
                    <DutiesCard clearance={clearance} />
                    <InspectionsCard clearance={clearance} />
                    <DocumentsCard shipment={shipment} clearance={clearance} />
                </div>
                <div className="space-y-6">
                    <TransitionCard clearance={clearance} allowedTransitions={allowedTransitions} />
                </div>
            </div>
        </AppLayout>
    );
}

function SummaryCard({ clearance }: { clearance: CustomsClearance }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Summary</p>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-slate-500">Customs office</dt><dd>{clearance.customs_office ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Submitted</dt><dd>{clearance.submitted_at ? new Date(clearance.submitted_at).toLocaleString() : '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Cleared</dt><dd>{clearance.cleared_at ? new Date(clearance.cleared_at).toLocaleString() : '—'}</dd></div>
            </dl>
            {clearance.notes && <p className="mt-4 text-sm text-slate-400">{clearance.notes}</p>}
        </article>
    );
}

function DutiesCard({ clearance }: { clearance: CustomsClearance }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ type: 'duty' as DutyType, description: '', amount: '', currency: 'AED' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${clearance.shipment_id}/customs-clearances/${clearance.id}/duties`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const pay = (dutyId: number) => {
        router.post(`/shipments/${clearance.shipment_id}/customs-clearances/${clearance.id}/duties/${dutyId}/pay`, {}, { preserveScroll: true });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Duties &amp; taxes</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <select value={data.type} onChange={(event) => setData('type', event.target.value as DutyType)} className={fieldClass}>
                        <option value="duty">Import duty</option>
                        <option value="tax">Tax</option>
                        <option value="fee">Fee</option>
                    </select>
                    <input placeholder="Description" value={data.description} onChange={(event) => setData('description', event.target.value)} required className={fieldClass} />
                    <input placeholder="Amount" type="number" step="0.01" value={data.amount} onChange={(event) => setData('amount', event.target.value)} required className={fieldClass} />
                    <input placeholder="Currency" value={data.currency} onChange={(event) => setData('currency', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} />
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add</button>
                    {errors.description && <p className="col-span-full text-xs text-rose-400">{errors.description}</p>}
                </form>
            )}

            <div className="mt-4 space-y-2">
                {clearance.duties.map((duty) => (
                    <div key={duty.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium capitalize">{duty.type} · {duty.description}</p>
                            <p className="text-xs text-slate-500">{duty.currency} {duty.amount}</p>
                        </div>
                        {duty.is_paid ? (
                            <span className="rounded-full border border-emerald-400/30 px-2 py-0.5 text-xs text-emerald-300">Paid</span>
                        ) : (
                            <button onClick={() => pay(duty.id)} className="text-xs text-cyan-300 hover:text-cyan-200">Mark paid</button>
                        )}
                    </div>
                ))}
                {clearance.duties.length === 0 && <p className="text-sm text-slate-500">No duties or taxes recorded yet.</p>}
            </div>
        </article>
    );
}

function InspectionsCard({ clearance }: { clearance: CustomsClearance }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ type: 'physical' as InspectionType, notes: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${clearance.shipment_id}/customs-clearances/${clearance.id}/inspections`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const complete = (inspectionId: number, status: 'passed' | 'failed') => {
        router.post(`/shipments/${clearance.shipment_id}/customs-clearances/${clearance.id}/inspections/${inspectionId}/complete`, { status }, { preserveScroll: true });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Inspections</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Schedule'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3">
                    <select value={data.type} onChange={(event) => setData('type', event.target.value as InspectionType)} className={fieldClass}>
                        <option value="physical">Physical exam</option>
                        <option value="xray">X-ray scan</option>
                        <option value="documentary">Documentary review</option>
                        <option value="canine">Canine inspection</option>
                        <option value="other">Other</option>
                    </select>
                    <input placeholder="Notes (optional)" value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={fieldClass} />
                    <button type="submit" disabled={processing} className="col-span-2 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Schedule</button>
                </form>
            )}

            <div className="mt-4 space-y-2">
                {clearance.inspections.map((inspection) => (
                    <div key={inspection.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium capitalize">{inspection.type}</p>
                            {inspection.notes && <p className="text-xs text-slate-500">{inspection.notes}</p>}
                        </div>
                        {inspection.status === 'scheduled' ? (
                            <div className="flex gap-2">
                                <button onClick={() => complete(inspection.id, 'passed')} className="text-xs text-emerald-300 hover:text-emerald-200">Pass</button>
                                <button onClick={() => complete(inspection.id, 'failed')} className="text-xs text-rose-400 hover:text-rose-300">Fail</button>
                            </div>
                        ) : (
                            <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${inspection.status === 'passed' ? 'border-emerald-400/30 text-emerald-300' : 'border-rose-400/30 text-rose-300'}`}>{inspection.status}</span>
                        )}
                    </div>
                ))}
                {clearance.inspections.length === 0 && <p className="text-sm text-slate-500">No inspections scheduled yet.</p>}
            </div>
        </article>
    );
}

function DocumentsCard({ shipment, clearance }: { shipment: { id: number }; clearance: CustomsClearance }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<{ category: DocumentCategory; file: File | null }>({ category: 'commercial_invoice', file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipment.id}/customs-clearances/${clearance.id}/documents`, {
            forceFormData: true,
            onSuccess: () => { reset(); setShowForm(false); },
        });
    };

    const remove = (documentId: number) => {
        if (confirm('Remove this document?')) {
            router.delete(`/shipments/${shipment.id}/customs-clearances/${clearance.id}/documents/${documentId}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Documents</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Upload'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 space-y-3">
                    <select value={data.category} onChange={(event) => setData('category', event.target.value as DocumentCategory)} className={fieldClass}>
                        <option value="customs_declaration">Customs declaration</option>
                        <option value="commercial_invoice">Commercial invoice</option>
                        <option value="packing_list">Packing list</option>
                        <option value="certificate_of_origin">Certificate of origin</option>
                        <option value="import_permit">Import permit</option>
                        <option value="other">Other</option>
                    </select>
                    <input
                        type="file"
                        onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                        required
                        className={fieldClass}
                    />
                    {errors.file && <p className="text-xs text-rose-400">{errors.file}</p>}
                    <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Upload</button>
                </form>
            )}

            <div className="mt-4 space-y-2">
                {clearance.documents.map((document) => (
                    <div key={document.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium">{document.original_filename}</p>
                            <p className="text-xs capitalize text-slate-500">{document.category.replace(/_/g, ' ')} · {document.uploaded_by?.name ?? 'System'}</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <a
                                href={`/shipments/${shipment.id}/customs-clearances/${clearance.id}/documents/${document.id}/download`}
                                className="text-xs text-cyan-300 hover:text-cyan-200"
                            >
                                Download
                            </a>
                            <button onClick={() => remove(document.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                        </div>
                    </div>
                ))}
                {clearance.documents.length === 0 && <p className="text-sm text-slate-500">No documents uploaded yet.</p>}
            </div>
        </article>
    );
}

function TransitionCard({ clearance, allowedTransitions }: { clearance: CustomsClearance; allowedTransitions: { value: string; label: string }[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        idempotency_key: newIdempotencyKey(),
        status: '',
        reason: '',
        next_shipment_status: 'in_transit',
    });

    const needsReason = data.status === 'held' || data.status === 'rejected';
    const isClearing = data.status === 'cleared';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${clearance.shipment_id}/customs-clearances/${clearance.id}/transitions`, {
            onSuccess: () => {
                reset('status', 'reason');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    if (allowedTransitions.length === 0) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <p className="text-sm font-semibold">Status</p>
                <p className="mt-3 text-sm text-slate-500">This clearance is in a terminal state.</p>
            </article>
        );
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Update status</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label className={labelClass}>New status</label>
                    <select value={data.status} onChange={(event) => setData('status', event.target.value)} required className={fieldClass}>
                        <option value="" disabled>Select a status…</option>
                        {allowedTransitions.map((transition) => (
                            <option key={transition.value} value={transition.value}>{transition.label}</option>
                        ))}
                    </select>
                    {errors.status && <p className="mt-1 text-xs text-rose-400">{errors.status}</p>}
                </div>

                {needsReason && (
                    <div>
                        <label className={labelClass}>Reason</label>
                        <textarea rows={2} value={data.reason} onChange={(event) => setData('reason', event.target.value)} required className={fieldClass} />
                        {errors.reason && <p className="mt-1 text-xs text-rose-400">{errors.reason}</p>}
                    </div>
                )}

                {isClearing && (
                    <div>
                        <label className={labelClass}>Shipment continues to</label>
                        <select value={data.next_shipment_status} onChange={(event) => setData('next_shipment_status', event.target.value)} className={fieldClass}>
                            <option value="in_transit">In transit</option>
                            <option value="out_for_delivery">Out for delivery</option>
                        </select>
                    </div>
                )}

                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Apply</button>
            </form>
        </article>
    );
}
