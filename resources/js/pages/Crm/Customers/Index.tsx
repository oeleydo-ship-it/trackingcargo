import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { Address, CustomerSummary, Paginated } from '../../../types';

interface CustomersIndexProps {
    customers: Paginated<CustomerSummary>;
    filters: { q: string };
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    inactive: 'text-slate-400 border-white/10',
    blocked: 'text-rose-300 border-rose-400/30',
};

const emptyAddress = {
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
};

const emptyForm = {
    type: 'individual' as 'individual' | 'business',
    name: '',
    company_name: '',
    email: '',
    phone: '',
    tax_id: '',
    identification_number: '',
    address: { ...emptyAddress },
};

export default function CustomersIndex({ customers, filters }: CustomersIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const [search, setSearch] = useState(filters.q);
    const importInput = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, errors, reset } = useForm(emptyForm);

    const setAddress = (patch: Partial<typeof emptyAddress>) => setData('address', { ...data.address, ...patch });

    // Inertia keys nested errors as "address.line1", which useForm's per-field
    // error typing does not cover.
    const addressErrors = errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/crm/customers', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        router.get('/crm/customers', { q: search }, { preserveState: true, replace: true });
    };

    const importFile = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) {
            return;
        }
        const form = new FormData();
        form.append('file', file);
        router.post('/crm/customers/import', form, { onFinish: () => { if (importInput.current) importInput.current.value = ''; } });
    };

    return (
        <AppLayout title="Customers">
            <Head title="Customers" />

            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <form onSubmit={submitSearch} className="flex-1 min-w-64 max-w-md">
                    <input
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search name, company, phone, email, tax ID…"
                        className={fieldClass}
                    />
                </form>
                <div className="flex items-center gap-2">
                    <label className="cursor-pointer rounded-lg border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                        Import CSV
                        <input ref={importInput} type="file" accept=".csv,.txt" onChange={importFile} className="hidden" />
                    </label>
                    <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                        {showForm ? 'Cancel' : 'New customer'}
                    </button>
                </div>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2">
                    <div>
                        <label htmlFor="type" className={labelClass}>Type</label>
                        <select id="type" value={data.type} onChange={(event) => setData('type', event.target.value as 'individual' | 'business')} className={fieldClass}>
                            <option value="individual">Individual</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="name" className={labelClass}>Name</label>
                        <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>
                    <div>
                        <label htmlFor="company_name" className={labelClass}>Company name</label>
                        <input id="company_name" value={data.company_name} onChange={(event) => setData('company_name', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="email" className={labelClass}>Email</label>
                        <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                        {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
                    </div>
                    <div>
                        <label htmlFor="phone" className={labelClass}>Phone</label>
                        <input id="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="tax_id" className={labelClass}>Tax ID</label>
                        <input id="tax_id" value={data.tax_id} onChange={(event) => setData('tax_id', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="identification_number" className={labelClass}>Identification number</label>
                        <input id="identification_number" value={data.identification_number} onChange={(event) => setData('identification_number', event.target.value)} className={fieldClass} />
                    </div>
                    <div className="sm:col-span-2 rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <p className="text-xs font-medium text-slate-400">Address <span className="text-slate-600">(optional)</span></p>
                        <p className="mt-1 mb-3 text-xs text-slate-600">
                            Saved as this customer’s default address, and offered up when they are named as a consignor
                            or consignee on a shipment. You can add more from the customer page later.
                        </p>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor="address_type" className={labelClass}>Type</label>
                                <select id="address_type" value={data.address.type} onChange={(event) => setAddress({ type: event.target.value as Address['type'] })} className={fieldClass}>
                                    <option value="shipping">Shipping</option>
                                    <option value="billing">Billing</option>
                                    <option value="pickup">Pickup</option>
                                    <option value="delivery">Delivery</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label htmlFor="address_label" className={labelClass}>Label</label>
                                <input id="address_label" value={data.address.label} onChange={(event) => setAddress({ label: event.target.value })} placeholder="Head office, warehouse…" className={fieldClass} />
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor="address_line1" className={labelClass}>Address line 1</label>
                                <input id="address_line1" value={data.address.line1} onChange={(event) => setAddress({ line1: event.target.value })} placeholder="Street and number" className={fieldClass} />
                                {addressErrors['address.line1'] && <p className="mt-1 text-xs text-rose-400">{addressErrors['address.line1']}</p>}
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor="address_line2" className={labelClass}>Address line 2</label>
                                <input id="address_line2" value={data.address.line2} onChange={(event) => setAddress({ line2: event.target.value })} placeholder="Building, unit, barangay" className={fieldClass} />
                            </div>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <label htmlFor="address_city" className={labelClass}>City</label>
                                <input id="address_city" value={data.address.city} onChange={(event) => setAddress({ city: event.target.value })} className={fieldClass} />
                                {addressErrors['address.city'] && <p className="mt-1 text-xs text-rose-400">{addressErrors['address.city']}</p>}
                            </div>
                            <div>
                                <label htmlFor="address_state" className={labelClass}>State</label>
                                <input id="address_state" value={data.address.state} onChange={(event) => setAddress({ state: event.target.value })} className={fieldClass} />
                            </div>
                            <div>
                                <label htmlFor="address_postal_code" className={labelClass}>Postal code</label>
                                <input id="address_postal_code" value={data.address.postal_code} onChange={(event) => setAddress({ postal_code: event.target.value })} className={fieldClass} />
                            </div>
                            <div>
                                <label htmlFor="address_country_code" className={labelClass}>Country</label>
                                <input id="address_country_code" value={data.address.country_code} onChange={(event) => setAddress({ country_code: event.target.value.toUpperCase() })} maxLength={2} placeholder="AE" className={fieldClass} />
                                {addressErrors['address.country_code'] && <p className="mt-1 text-xs text-rose-400">{addressErrors['address.country_code']}</p>}
                            </div>
                        </div>

                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor="address_contact_name" className={labelClass}>Contact at address</label>
                                <input id="address_contact_name" value={data.address.contact_name} onChange={(event) => setAddress({ contact_name: event.target.value })} className={fieldClass} />
                            </div>
                            <div>
                                <label htmlFor="address_contact_phone" className={labelClass}>Contact phone</label>
                                <input id="address_contact_phone" value={data.address.contact_phone} onChange={(event) => setAddress({ contact_phone: event.target.value })} className={fieldClass} />
                            </div>
                        </div>
                    </div>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Creating…' : 'Create customer'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Number</th>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Contact</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {customers.data.map((customer) => (
                            <tr key={customer.id}>
                                <td className="px-4 py-3 font-mono text-xs text-slate-400">{customer.customer_number}</td>
                                <td className="px-4 py-3">
                                    <Link href={`/crm/customers/${customer.id}`} className="font-medium text-cyan-300 hover:text-cyan-200">{customer.name}</Link>
                                    {customer.company_name && <p className="text-xs text-slate-500">{customer.company_name}</p>}
                                </td>
                                <td className="px-4 py-3 text-slate-400">
                                    <p>{customer.email ?? '—'}</p>
                                    <p className="text-xs text-slate-500">{customer.phone ?? ''}</p>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{customer.branch?.name ?? 'Company-wide'}</td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[customer.status] ?? 'border-white/10 text-slate-400'}`}>{customer.status}</span></td>
                            </tr>
                        ))}
                        {customers.data.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No customers yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {customers.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>{customers.from ?? 0}–{customers.to ?? 0} of {customers.total}</span>
                    <div className="flex gap-2">
                        <button
                            disabled={customers.current_page <= 1}
                            onClick={() => router.get('/crm/customers', { q: search, page: customers.current_page - 1 }, { preserveState: true })}
                            className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <button
                            disabled={customers.current_page >= customers.last_page}
                            onClick={() => router.get('/crm/customers', { q: search, page: customers.current_page + 1 }, { preserveState: true })}
                            className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
