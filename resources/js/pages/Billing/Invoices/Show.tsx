import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { Invoice, PaymentMethod } from '../../../types';

interface ShowProps {
    customer: { id: number; name: string; customer_number: string };
    invoice: Invoice;
    allowedTransitions: { value: string; label: string }[];
    stripe_ready: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    draft: 'text-slate-400 border-white/10',
    issued: 'text-blue-300 border-blue-400/30',
    partially_paid: 'text-amber-300 border-amber-400/30',
    paid: 'text-emerald-300 border-emerald-400/30',
    void: 'text-rose-300 border-rose-400/30',
};

function newIdempotencyKey(): string {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function Show({ customer, invoice, allowedTransitions, stripe_ready }: ShowProps) {
    return (
        <AppLayout title={invoice.invoice_number}>
            <Head title={invoice.invoice_number} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link href={`/crm/customers/${customer.id}`} className="text-xs text-cyan-300 hover:text-cyan-200">{customer.name} ({customer.customer_number})</Link>
                    <h2 className="font-mono text-xl font-semibold">{invoice.invoice_number}</h2>
                </div>
                <span className={`rounded-full border px-3 py-1 text-xs capitalize ${statusColor[invoice.status] ?? 'border-white/10 text-slate-400'}`}>{invoice.status.replace(/_/g, ' ')}</span>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <SummaryCard invoice={invoice} />
                    <ItemsCard customerId={customer.id} invoice={invoice} />
                    <PaymentsCard invoice={invoice} customerId={customer.id} />
                </div>
                <div className="space-y-6">
                    <TransitionCard customerId={customer.id} invoice={invoice} allowedTransitions={allowedTransitions} />
                    {(invoice.status === 'issued' || invoice.status === 'partially_paid') && (
                        <RecordPaymentCard customerId={customer.id} invoice={invoice} />
                    )}
                    <CheckoutCard customerId={customer.id} invoice={invoice} stripeReady={stripe_ready} />
                </div>
            </div>
        </AppLayout>
    );
}

function SummaryCard({ invoice }: { invoice: Invoice }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Summary</p>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-slate-500">Currency</dt><dd>{invoice.currency}</dd></div>
                <div><dt className="text-xs text-slate-500">Subtotal</dt><dd>{invoice.subtotal}</dd></div>
                <div><dt className="text-xs text-slate-500">Total</dt><dd>{invoice.total}</dd></div>
                <div><dt className="text-xs text-slate-500">Paid</dt><dd>{invoice.amount_paid}</dd></div>
                <div><dt className="text-xs text-slate-500">Balance due</dt><dd className="font-semibold">{invoice.balance_due}</dd></div>
                <div><dt className="text-xs text-slate-500">Due date</dt><dd>{invoice.due_date?.slice(0, 10) ?? '—'}</dd></div>
            </dl>
            {invoice.notes && <p className="mt-4 text-sm text-slate-400">{invoice.notes}</p>}
        </article>
    );
}

function TransitionCard({ customerId, invoice, allowedTransitions }: { customerId: number; invoice: Invoice; allowedTransitions: { value: string; label: string }[] }) {
    const { data, setData, post, processing, errors } = useForm({ status: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customerId}/invoices/${invoice.id}/transitions`);
    };

    if (allowedTransitions.length === 0) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <p className="text-sm font-semibold">Status</p>
                <p className="mt-3 text-sm text-slate-500">
                    {invoice.status === 'partially_paid'
                        ? 'No status change is available while payments are outstanding — the remaining balance will move this invoice to Paid automatically.'
                        : 'This invoice is in a terminal state.'}
                </p>
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

function ItemsCard({ customerId, invoice }: { customerId: number; invoice: Invoice }) {
    const [showForm, setShowForm] = useState(false);
    const [fromShipment, setFromShipment] = useState(true);
    const isDraft = invoice.status === 'draft';

    const { data, setData, post, processing, errors, reset } = useForm({
        from_shipment: true,
        shipment_id: '' as number | '',
        rate_card_id: '' as number | '',
        description: '',
        quantity: '' as number | '',
        unit_price: '' as number | '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setData('from_shipment', fromShipment);
        post(`/crm/customers/${customerId}/invoices/${invoice.id}/items`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (itemId: number) => {
        if (confirm('Remove this line item?')) {
            router.delete(`/crm/customers/${customerId}/invoices/${invoice.id}/items/${itemId}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Line items</p>
                {isDraft && (
                    <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add item'}</button>
                )}
            </div>

            {showForm && isDraft && (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-xl border border-white/5 bg-black/20 p-4">
                    <div className="flex gap-2 text-xs">
                        <button type="button" onClick={() => setFromShipment(true)} className={`rounded-full px-3 py-1 ${fromShipment ? 'bg-cyan-400 text-slate-950' : 'border border-white/10 text-slate-400'}`}>From shipment</button>
                        <button type="button" onClick={() => setFromShipment(false)} className={`rounded-full px-3 py-1 ${!fromShipment ? 'bg-cyan-400 text-slate-950' : 'border border-white/10 text-slate-400'}`}>Manual</button>
                    </div>

                    {fromShipment ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label className={labelClass}>Shipment ID</label>
                                <input value={data.shipment_id} onChange={(event) => setData('shipment_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                                {errors.shipment_id && <p className="mt-1 text-xs text-rose-400">{errors.shipment_id}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Rate card ID</label>
                                <input value={data.rate_card_id} onChange={(event) => setData('rate_card_id', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                                {errors.rate_card_id && <p className="mt-1 text-xs text-rose-400">{errors.rate_card_id}</p>}
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="sm:col-span-3">
                                <label className={labelClass}>Description</label>
                                <input value={data.description} onChange={(event) => setData('description', event.target.value)} required className={fieldClass} />
                                {errors.description && <p className="mt-1 text-xs text-rose-400">{errors.description}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Quantity</label>
                                <input type="number" step="0.001" min="0.001" value={data.quantity} onChange={(event) => setData('quantity', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                                {errors.quantity && <p className="mt-1 text-xs text-rose-400">{errors.quantity}</p>}
                            </div>
                            <div>
                                <label className={labelClass}>Unit price</label>
                                <input type="number" step="0.01" min="0" value={data.unit_price} onChange={(event) => setData('unit_price', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                                {errors.unit_price && <p className="mt-1 text-xs text-rose-400">{errors.unit_price}</p>}
                            </div>
                        </div>
                    )}

                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add item</button>
                </form>
            )}

            <div className="mt-4 overflow-hidden rounded-xl border border-white/5">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Description</th>
                            <th className="px-3 py-2">Qty</th>
                            <th className="px-3 py-2">Unit price</th>
                            <th className="px-3 py-2">Amount</th>
                            {isDraft && <th className="px-3 py-2" />}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {invoice.items.map((item) => (
                            <tr key={item.id}>
                                <td className="px-3 py-2">{item.description}{item.shipment && <span className="ml-1 text-xs text-slate-500">({item.shipment.tracking_number})</span>}</td>
                                <td className="px-3 py-2">{item.quantity}</td>
                                <td className="px-3 py-2">{item.unit_price}</td>
                                <td className="px-3 py-2">{item.amount}</td>
                                {isDraft && (
                                    <td className="px-3 py-2 text-right">
                                        <button onClick={() => remove(item.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                                    </td>
                                )}
                            </tr>
                        ))}
                        {invoice.items.length === 0 && (
                            <tr><td colSpan={isDraft ? 5 : 4} className="px-3 py-4 text-center text-slate-500">No line items yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </article>
    );
}

function RecordPaymentCard({ customerId, invoice }: { customerId: number; invoice: Invoice }) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        idempotency_key: string;
        amount: number | '';
        method: PaymentMethod;
        reference: string;
        notes: string;
    }>({
        idempotency_key: newIdempotencyKey(),
        amount: '',
        method: 'cash',
        reference: '',
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customerId}/invoices/${invoice.id}/payments`, {
            onSuccess: () => {
                reset('amount', 'reference', 'notes');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Record payment</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label className={labelClass}>Amount</label>
                    <input type="number" step="0.01" min="0.01" value={data.amount} onChange={(event) => setData('amount', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                    {errors.amount && <p className="mt-1 text-xs text-rose-400">{errors.amount}</p>}
                </div>
                <div>
                    <label className={labelClass}>Method</label>
                    <select value={data.method} onChange={(event) => setData('method', event.target.value as PaymentMethod)} className={fieldClass}>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="card">Card</option>
                        <option value="cod">Cash on delivery</option>
                    </select>
                </div>
                <div>
                    <label className={labelClass}>Reference (optional)</label>
                    <input value={data.reference} onChange={(event) => setData('reference', event.target.value)} className={fieldClass} />
                </div>
                <textarea placeholder="Notes (optional)" rows={2} value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={fieldClass} />
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Recording…' : 'Record payment'}
                </button>
            </form>
        </article>
    );
}

function CheckoutCard({ customerId, invoice, stripeReady }: { customerId: number; invoice: Invoice; stripeReady: boolean }) {
    const startCheckout = () => {
        router.post(`/crm/customers/${customerId}/invoices/${invoice.id}/checkout`, {}, {
            onError: () => {},
        });
    };

    if (!stripeReady || !((invoice.status === 'issued' || invoice.status === 'partially_paid') && Number(invoice.balance_due) > 0)) {
        return null;
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Online checkout</p>
            <p className="mt-1 text-sm text-slate-500">
                Create a secure Stripe checkout session and let the customer pay the outstanding balance online.
            </p>
            <button
                type="button"
                onClick={startCheckout}
                className="mt-4 w-full rounded-lg bg-cyan-400 px-3 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-300"
            >
                Pay online with card
            </button>
        </article>
    );
}

function PaymentsCard({ invoice, customerId }: { invoice: Invoice; customerId: number }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Payments</p>
            <div className="mt-4 space-y-2">
                {invoice.payments.map((payment) => (
                    <div key={payment.id} className="space-y-2 rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium">{payment.amount} {payment.currency} <span className="text-xs capitalize text-slate-500">({payment.method.replace(/_/g, ' ')})</span></p>
                            <p className="text-xs text-slate-500">{payment.actor?.name ?? 'System'} · {new Date(payment.received_at).toLocaleString()}{payment.reference && ` · Ref: ${payment.reference}`}</p>
                        </div>
                        {payment.method === 'card' && isStripePayment(payment.reference) && Number(payment.amount) > 0 && (
                            <RefundPaymentForm customerId={customerId} invoice={invoice} payment={payment} />
                        )}
                    </div>
                ))}
                {invoice.payments.length === 0 && <p className="text-sm text-slate-500">No payments recorded yet.</p>}
            </div>
        </article>
    );
}

function RefundPaymentForm({
    customerId,
    invoice,
    payment,
}: {
    customerId: number;
    invoice: Invoice;
    payment: {
        id: number;
        amount: string;
        currency: string;
        method: PaymentMethod;
        reference: string | null;
    };
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        amount: number | null;
        reason: string;
    }>({
        amount: null,
        reason: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customerId}/invoices/${invoice.id}/payments/${payment.id}/refund`, {
            onSuccess: () => reset('amount', 'reason'),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-2 pt-2 sm:grid-cols-[1fr_auto] sm:items-end">
            <div>
                <label className={labelClass}>Partial refund (optional)</label>
                <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    placeholder={`Max ${payment.amount} ${payment.currency}`}
                    value={data.amount ?? ''}
                    onChange={(event) => setData('amount', event.target.value ? Number(event.target.value) : null)}
                    className={fieldClass}
                />
                {errors.amount && <p className="mt-1 text-xs text-rose-400">{errors.amount}</p>}
            </div>
            <div>
                <label className={labelClass}>Reason (optional)</label>
                <input
                    value={data.reason}
                    onChange={(event) => setData('reason', event.target.value)}
                    className={fieldClass}
                    placeholder="Customer request"
                />
                {errors.reason && <p className="mt-1 text-xs text-rose-400">{errors.reason}</p>}
                <button type="submit" disabled={processing} className="mt-2 w-full rounded-lg border border-cyan-300/50 px-3 py-2 text-sm text-cyan-200 transition hover:border-cyan-200 hover:text-cyan-100 disabled:opacity-60">
                    {processing ? 'Submitting…' : 'Refund'}
                </button>
            </div>
        </form>
    );
}

function isStripePayment(reference: string | null): boolean {
    return reference !== null && reference.startsWith('stripe_payment_intent:');
}
