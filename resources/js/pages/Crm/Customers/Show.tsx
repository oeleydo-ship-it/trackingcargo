import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { Address, Customer } from '../../../types';

interface ShowProps {
    customer: Customer;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    inactive: 'text-slate-400 border-white/10',
    blocked: 'text-rose-300 border-rose-400/30',
};

export default function Show({ customer }: ShowProps) {
    return (
        <AppLayout title={customer.name}>
            <Head title={customer.name} />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <p className="font-mono text-xs text-slate-500">{customer.customer_number}</p>
                    <h2 className="text-xl font-semibold">{customer.name}</h2>
                    {customer.company_name && <p className="text-sm text-slate-400">{customer.company_name}</p>}
                </div>
                <div className="flex items-center gap-3">
                    <span className={`rounded-full border px-3 py-1 text-xs capitalize ${statusColor[customer.status] ?? 'border-white/10 text-slate-400'}`}>{customer.status}</span>
                    <DeleteCustomerButton customer={customer} />
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <DetailsCard customer={customer} />
                    <InvoicesCard customer={customer} />
                    <ContactsCard customer={customer} />
                    <AddressesCard customer={customer} />
                </div>
                <div className="space-y-6">
                    <PortalCard customer={customer} />
                    <NotesCard customer={customer} />
                </div>
            </div>
        </AppLayout>
    );
}

/**
 * Removing a customer. Shipments already booked for them keep the names typed
 * on their own parties, so nothing disappears from those; the server refuses
 * only while the customer still owns invoices or a rate card.
 */
function DeleteCustomerButton({ customer }: { customer: Customer }) {
    const [confirming, setConfirming] = useState(false);
    const hasInvoices = customer.invoices.length > 0;

    if (hasInvoices) {
        return (
            <span className="cursor-help rounded-lg border border-white/10 px-3 py-1.5 text-xs text-slate-600" title="This customer has invoices, so it can't be deleted — set it to inactive instead">
                Delete
            </span>
        );
    }

    if (confirming) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="text-slate-400">
                    Delete {customer.name}?{customer.portal_user ? ' Their portal login will be suspended.' : ''}
                </span>
                <button onClick={() => router.delete(`/crm/customers/${customer.id}`)} className="rounded-lg bg-rose-500 px-3 py-1.5 font-semibold text-on-accent transition hover:bg-rose-400">
                    Delete
                </button>
                <button onClick={() => setConfirming(false)} className="rounded-lg border border-white/10 px-3 py-1.5 text-slate-400 transition hover:text-slate-200">
                    Cancel
                </button>
            </div>
        );
    }

    return (
        <button onClick={() => setConfirming(true)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-rose-400 transition hover:border-rose-400/50 hover:text-rose-300">
            Delete
        </button>
    );
}

function DetailsCard({ customer }: { customer: Customer }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, patch, processing, errors } = useForm({
        type: customer.type,
        name: customer.name,
        company_name: customer.company_name ?? '',
        email: customer.email ?? '',
        phone: customer.phone ?? '',
        tax_id: customer.tax_id ?? '',
        identification_number: customer.identification_number ?? '',
        credit_limit: customer.credit_limit ?? ('' as number | string),
        status: customer.status,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/crm/customers/${customer.id}`, { onSuccess: () => setEditing(false) });
    };

    if (!editing) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <div className="flex items-center justify-between">
                    <p className="text-sm font-semibold">Details</p>
                    <button onClick={() => setEditing(true)} className="text-xs text-cyan-300 hover:text-cyan-200">Edit</button>
                </div>
                <dl className="mt-4 grid grid-cols-2 gap-4 text-sm">
                    <div><dt className="text-xs text-slate-500">Type</dt><dd className="capitalize">{customer.type}</dd></div>
                    <div><dt className="text-xs text-slate-500">Email</dt><dd>{customer.email ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Phone</dt><dd>{customer.phone ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Tax ID</dt><dd>{customer.tax_id ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Identification number</dt><dd>{customer.identification_number ?? '—'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Branch</dt><dd>{customer.branch?.name ?? 'Company-wide'}</dd></div>
                    <div><dt className="text-xs text-slate-500">Credit limit</dt><dd>{customer.credit_limit ?? 'No limit'}</dd></div>
                </dl>
            </article>
        );
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label className={labelClass}>Type</label>
                    <select value={data.type} onChange={(event) => setData('type', event.target.value as typeof data.type)} className={fieldClass}>
                        <option value="individual">Individual</option>
                        <option value="business">Business</option>
                    </select>
                </div>
                <div>
                    <label className={labelClass}>Status</label>
                    <select value={data.status} onChange={(event) => setData('status', event.target.value as typeof data.status)} className={fieldClass}>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
                <div>
                    <label className={labelClass}>Name</label>
                    <input value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                </div>
                <div>
                    <label className={labelClass}>Company name</label>
                    <input value={data.company_name} onChange={(event) => setData('company_name', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label className={labelClass}>Email</label>
                    <input type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                    {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
                </div>
                <div>
                    <label className={labelClass}>Phone</label>
                    <input value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label className={labelClass}>Tax ID</label>
                    <input value={data.tax_id} onChange={(event) => setData('tax_id', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label className={labelClass}>Identification number</label>
                    <input value={data.identification_number} onChange={(event) => setData('identification_number', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label className={labelClass}>Credit limit (optional)</label>
                    <input type="number" step="0.01" min="0" value={data.credit_limit} onChange={(event) => setData('credit_limit', event.target.value ? Number(event.target.value) : '')} className={fieldClass} placeholder="No limit" />
                    {errors.credit_limit && <p className="mt-1 text-xs text-rose-400">{errors.credit_limit}</p>}
                </div>
                <div className="sm:col-span-2 flex gap-2">
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Save</button>
                    <button type="button" onClick={() => setEditing(false)} className="rounded-lg border border-white/10 px-4 py-2 text-sm text-slate-300 hover:text-white">Cancel</button>
                </div>
            </form>
        </article>
    );
}

const invoiceStatusColor: Record<string, string> = {
    draft: 'text-slate-400 border-white/10',
    issued: 'text-blue-300 border-blue-400/30',
    partially_paid: 'text-amber-300 border-amber-400/30',
    paid: 'text-emerald-300 border-emerald-400/30',
    void: 'text-rose-300 border-rose-400/30',
};

function InvoicesCard({ customer }: { customer: Customer }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ currency: 'AED', due_date: '', notes: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customer.id}/invoices`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Invoices</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ New invoice'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-3">
                    <input placeholder="Currency" value={data.currency} onChange={(event) => setData('currency', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} />
                    <input type="date" placeholder="Due date (optional)" value={data.due_date} onChange={(event) => setData('due_date', event.target.value)} className={fieldClass} />
                    <input placeholder="Notes (optional)" value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={fieldClass} />
                    {errors.currency && <p className="text-xs text-rose-400 sm:col-span-3">{errors.currency}</p>}
                    <div className="sm:col-span-3">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Create draft invoice</button>
                    </div>
                </form>
            )}

            <div className="mt-4 space-y-2">
                {customer.invoices.map((invoice) => (
                    <Link
                        key={invoice.id}
                        href={`/crm/customers/${customer.id}/invoices/${invoice.id}`}
                        className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm transition hover:border-white/10 hover:bg-white/5"
                    >
                        <div>
                            <p className="font-mono font-medium">{invoice.invoice_number}</p>
                            <p className="text-xs text-slate-500">Total {invoice.total} {invoice.currency} · Balance due {invoice.balance_due}</p>
                        </div>
                        <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${invoiceStatusColor[invoice.status] ?? 'border-white/10 text-slate-400'}`}>{invoice.status.replace(/_/g, ' ')}</span>
                    </Link>
                ))}
                {customer.invoices.length === 0 && <p className="text-sm text-slate-500">No invoices yet.</p>}
            </div>
        </article>
    );
}

function ContactsCard({ customer }: { customer: Customer }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ name: '', title: '', email: '', phone: '', is_primary: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customer.id}/contacts`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (contactId: number) => {
        if (confirm('Remove this contact?')) {
            router.delete(`/crm/customers/${customer.id}/contacts/${contactId}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Contacts</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2">
                    <input aria-label="Name" placeholder="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    <input aria-label="Title" placeholder="Title" value={data.title} onChange={(event) => setData('title', event.target.value)} className={fieldClass} />
                    <input aria-label="Email" placeholder="Email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                    <input aria-label="Phone" placeholder="Phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    <label className="flex items-center gap-2 text-sm text-slate-400 sm:col-span-2">
                        <input type="checkbox" checked={data.is_primary} onChange={(event) => setData('is_primary', event.target.checked)} className="size-4 rounded border-white/20 bg-white/5 text-cyan-400" />
                        Primary contact
                    </label>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add contact</button>
                    </div>
                </form>
            )}

            <div className="mt-4 space-y-3">
                {customer.contacts.map((contact) => (
                    <div key={contact.id} className="flex items-start justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium">{contact.name} {contact.is_primary && <span className="ml-1 rounded-full bg-cyan-400/10 px-1.5 py-0.5 text-[10px] text-cyan-300">Primary</span>}</p>
                            {contact.title && <p className="text-xs text-slate-500">{contact.title}</p>}
                            <p className="text-xs text-slate-500">{contact.email ?? ''} {contact.phone ?? ''}</p>
                        </div>
                        <button onClick={() => remove(contact.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                    </div>
                ))}
                {customer.contacts.length === 0 && <p className="text-sm text-slate-500">No contacts yet.</p>}
            </div>
        </article>
    );
}

function AddressesCard({ customer }: { customer: Customer }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({
        type: 'shipping' as Address['type'],
        label: '',
        line1: '',
        line2: '',
        city: '',
        state: '',
        postal_code: '',
        country_code: '',
        contact_name: '',
        contact_phone: '',
        is_default: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customer.id}/addresses`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (addressId: number) => {
        if (confirm('Remove this address?')) {
            router.delete(`/crm/customers/${customer.id}/addresses/${addressId}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Addresses</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2">
                    <select aria-label="Address type" value={data.type} onChange={(event) => setData('type', event.target.value as Address['type'])} className={fieldClass}>
                        <option value="billing">Billing</option>
                        <option value="shipping">Shipping</option>
                        <option value="pickup">Pickup</option>
                        <option value="delivery">Delivery</option>
                        <option value="other">Other</option>
                    </select>
                    <input aria-label="Label" placeholder="Label" value={data.label} onChange={(event) => setData('label', event.target.value)} className={fieldClass} />
                    <input aria-label="Address line 1" placeholder="Address line 1" value={data.line1} onChange={(event) => setData('line1', event.target.value)} required className={`${fieldClass} sm:col-span-2`} />
                    <input aria-label="Address line 2" placeholder="Address line 2" value={data.line2} onChange={(event) => setData('line2', event.target.value)} className={`${fieldClass} sm:col-span-2`} />
                    <input aria-label="City" placeholder="City" value={data.city} onChange={(event) => setData('city', event.target.value)} required className={fieldClass} />
                    <input aria-label="State" placeholder="State" value={data.state} onChange={(event) => setData('state', event.target.value)} className={fieldClass} />
                    <input aria-label="Postal code" placeholder="Postal code" value={data.postal_code} onChange={(event) => setData('postal_code', event.target.value)} className={fieldClass} />
                    <input aria-label="Country code" placeholder="Country code (AE)" value={data.country_code} onChange={(event) => setData('country_code', event.target.value.toUpperCase())} maxLength={2} required className={fieldClass} />
                    <input aria-label="Contact name" placeholder="Contact name" value={data.contact_name} onChange={(event) => setData('contact_name', event.target.value)} className={fieldClass} />
                    <input aria-label="Contact phone" placeholder="Contact phone" value={data.contact_phone} onChange={(event) => setData('contact_phone', event.target.value)} className={fieldClass} />
                    <label className="flex items-center gap-2 text-sm text-slate-400 sm:col-span-2">
                        <input type="checkbox" checked={data.is_default} onChange={(event) => setData('is_default', event.target.checked)} className="size-4 rounded border-white/20 bg-white/5 text-cyan-400" />
                        Default for this type
                    </label>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add address</button>
                    </div>
                </form>
            )}

            <div className="mt-4 space-y-3">
                {customer.addresses.map((address) => (
                    <div key={address.id} className="flex items-start justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium capitalize">{address.type} {address.is_default && <span className="ml-1 rounded-full bg-cyan-400/10 px-1.5 py-0.5 text-[10px] text-cyan-300">Default</span>}</p>
                            <p className="text-xs text-slate-500">{[address.line1, address.line2, address.city, address.state, address.postal_code, address.country_code].filter(Boolean).join(', ')}</p>
                        </div>
                        <button onClick={() => remove(address.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                    </div>
                ))}
                {customer.addresses.length === 0 && <p className="text-sm text-slate-500">No addresses yet.</p>}
            </div>
        </article>
    );
}

function PortalCard({ customer }: { customer: Customer }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ name: customer.name, email: customer.email ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customer.id}/portal`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const unlink = () => {
        if (confirm('Unlink the portal account? The user login itself is not deleted.')) {
            router.delete(`/crm/customers/${customer.id}/portal`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Portal access</p>
            {customer.portal_user ? (
                <div className="mt-4 flex items-center justify-between text-sm">
                    <div>
                        <p>{customer.portal_user.name}</p>
                        <p className="text-xs text-slate-500">{customer.portal_user.email}</p>
                    </div>
                    <button onClick={unlink} className="text-xs text-rose-400 hover:text-rose-300">Unlink</button>
                </div>
            ) : showForm ? (
                <form onSubmit={submit} className="mt-4 space-y-3">
                    <input placeholder="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    <input placeholder="Email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required className={fieldClass} />
                    {errors.email && <p className="text-xs text-rose-400">{errors.email}</p>}
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Send invitation</button>
                </form>
            ) : (
                <button onClick={() => setShowForm(true)} className="mt-4 text-xs text-cyan-300 hover:text-cyan-200">+ Invite portal user</button>
            )}
        </article>
    );
}

function NotesCard({ customer }: { customer: Customer }) {
    const { data, setData, post, processing, reset } = useForm({ body: '', is_pinned: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/crm/customers/${customer.id}/notes`, { onSuccess: () => reset() });
    };

    const remove = (noteId: number) => {
        if (confirm('Remove this note?')) {
            router.delete(`/crm/customers/${customer.id}/notes/${noteId}`);
        }
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Notes</p>
            <form onSubmit={submit} className="mt-4 space-y-2">
                <textarea value={data.body} onChange={(event) => setData('body', event.target.value)} required rows={3} placeholder="Add a note…" className={fieldClass} />
                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-xs text-slate-400">
                        <input type="checkbox" checked={data.is_pinned} onChange={(event) => setData('is_pinned', event.target.checked)} className="size-3.5 rounded border-white/20 bg-white/5 text-cyan-400" />
                        Pin
                    </label>
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-1.5 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add note</button>
                </div>
            </form>

            <div className="mt-4 space-y-3">
                {[...customer.notes].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned)).map((note) => (
                    <div key={note.id} className="rounded-xl border border-white/5 p-3 text-sm">
                        <div className="flex items-start justify-between gap-2">
                            <p className="whitespace-pre-wrap">{note.is_pinned && '📌 '}{note.body}</p>
                            <button onClick={() => remove(note.id)} className="shrink-0 text-xs text-rose-400 hover:text-rose-300">Remove</button>
                        </div>
                        <p className="mt-1 text-xs text-slate-500">{note.author?.name ?? 'System'} · {new Date(note.created_at).toLocaleDateString()}</p>
                    </div>
                ))}
                {customer.notes.length === 0 && <p className="text-sm text-slate-500">No notes yet.</p>}
            </div>
        </article>
    );
}
