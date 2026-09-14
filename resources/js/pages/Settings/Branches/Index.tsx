import { Head, router, useForm } from '@inertiajs/react';
import { Fragment, useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import CountrySelect from '../../../components/CountrySelect';
import type { Branch } from '../../../types';

interface BranchesIndexProps {
    branches: Branch[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusOptions = ['active', 'suspended', 'inactive'] as const;

export default function BranchesIndex({ branches }: BranchesIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [removeError, setRemoveError] = useState('');

    const startCreate = () => {
        setEditingId(null);
        setShowForm((value) => !value);
    };

    const startEdit = (branch: Branch) => {
        setShowForm(false);
        setEditingId((current) => (current === branch.id ? null : branch.id));
    };

    const remove = (branch: Branch) => {
        if (confirm(`Remove branch "${branch.name}"?`)) {
            setRemoveError('');
            router.delete(`/settings/branches/${branch.id}`, { onError: (errors) => setRemoveError(errors.branch ?? 'Unable to remove this branch.') });
        }
    };

    return (
        <SettingsLayout title="Branches">
            <Head title="Branches" />
            {removeError && <p role="alert" className="mb-4 rounded-xl border border-rose-400/30 p-3 text-sm text-rose-300">{removeError}</p>}

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{branches.length} branch{branches.length === 1 ? '' : 'es'}</p>
                <button onClick={startCreate} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New branch'}
                </button>
            </div>

            {showForm && (
                <div className="mb-6 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <BranchForm onDone={() => setShowForm(false)} />
                </div>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Prefix</th>
                            <th className="px-4 py-3">City</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Head office</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {branches.map((branch) => (
                            <Fragment key={branch.id}>
                                <tr>
                                    <td className="px-4 py-3 font-medium">{branch.name}</td>
                                    <td className="px-4 py-3 text-slate-400">{branch.code}</td>
                                    <td className="px-4 py-3 font-mono text-xs text-slate-400">{branch.tracking_prefix}</td>
                                    <td className="px-4 py-3 text-slate-400">{branch.city}</td>
                                    <td className="px-4 py-3"><span className="rounded-full border border-white/10 px-2 py-0.5 text-xs capitalize text-slate-400">{branch.status}</span></td>
                                    <td className="px-4 py-3 text-slate-400">{branch.is_head_office ? 'Yes' : '—'}</td>
                                    <td className="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                                        <button onClick={() => startEdit(branch)} className="text-xs text-cyan-300 hover:text-cyan-200">
                                            {editingId === branch.id ? 'Cancel' : 'Edit'}
                                        </button>
                                        <button onClick={() => remove(branch)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                                    </td>
                                </tr>
                                {editingId === branch.id && (
                                    <tr className="bg-white/[0.02]">
                                        <td colSpan={7} className="px-4 py-5">
                                            <BranchForm branch={branch} onDone={() => setEditingId(null)} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                        {branches.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-500">No branches yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </SettingsLayout>
    );
}

function BranchForm({ branch, onDone }: { branch?: Branch; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: branch?.name ?? '',
        code: branch?.code ?? '',
        tracking_prefix: branch?.tracking_prefix ?? '',
        email: branch?.email ?? '',
        phone: branch?.phone ?? '',
        country_code: branch?.country_code ?? '',
        city: branch?.city ?? '',
        address: branch?.address ?? '',
        timezone: branch?.timezone ?? 'UTC',
        status: branch?.status ?? 'active',
        is_head_office: branch?.is_head_office ?? false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const onSuccess = () => {
            reset();
            onDone();
        };

        if (branch) {
            patch(`/settings/branches/${branch.id}`, { onSuccess });
        } else {
            post('/settings/branches', { onSuccess });
        }
    };

    const id = (field: string) => (branch ? `branch-${branch.id}-${field}` : `branch-new-${field}`);

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <div>
                <label htmlFor={id('name')} className={labelClass}>Name</label>
                <input id={id('name')} value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
            </div>
            <div>
                <label htmlFor={id('code')} className={labelClass}>Code</label>
                <input id={id('code')} value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required className={fieldClass} />
                {errors.code && <p className="mt-1 text-xs text-rose-400">{errors.code}</p>}
            </div>
            <div>
                <label htmlFor={id('tracking_prefix')} className={labelClass}>Tracking prefix</label>
                <input id={id('tracking_prefix')} value={data.tracking_prefix} onChange={(event) => setData('tracking_prefix', event.target.value.toUpperCase())} required className={`${fieldClass} font-mono`} />
                {errors.tracking_prefix && <p className="mt-1 text-xs text-rose-400">{errors.tracking_prefix}</p>}
                <p className="mt-1 text-xs text-slate-600">Fills the <span className="font-mono">{'{branch}'}</span> slot of the tracking number pattern.</p>
            </div>
            <div>
                <label htmlFor={id('country_code')} className={labelClass}>Country</label>
                <CountrySelect id={id('country_code')} value={data.country_code} onChange={(event) => setData('country_code', event.target.value)} required className={fieldClass} />
                {errors.country_code && <p className="mt-1 text-xs text-rose-400">{errors.country_code}</p>}
            </div>
            <div>
                <label htmlFor={id('city')} className={labelClass}>City</label>
                <input id={id('city')} value={data.city} onChange={(event) => setData('city', event.target.value)} required className={fieldClass} />
                {errors.city && <p className="mt-1 text-xs text-rose-400">{errors.city}</p>}
            </div>
            <div>
                <label htmlFor={id('timezone')} className={labelClass}>Timezone</label>
                <input id={id('timezone')} value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} required className={fieldClass} />
                {errors.timezone && <p className="mt-1 text-xs text-rose-400">{errors.timezone}</p>}
            </div>
            <div>
                <label htmlFor={id('email')} className={labelClass}>Email</label>
                <input id={id('email')} type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
            </div>
            <div>
                <label htmlFor={id('phone')} className={labelClass}>Phone</label>
                <input id={id('phone')} value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                {errors.phone && <p className="mt-1 text-xs text-rose-400">{errors.phone}</p>}
            </div>
            <div className="sm:col-span-2">
                <label htmlFor={id('address')} className={labelClass}>Address</label>
                <textarea id={id('address')} value={data.address} onChange={(event) => setData('address', event.target.value)} rows={3} placeholder="Street and building, unit, district, state/province, postal code" className={fieldClass} />
                {errors.address && <p className="mt-1 text-xs text-rose-400">{errors.address}</p>}
            </div>
            {branch && (
                <div>
                    <label htmlFor={id('status')} className={labelClass}>Status</label>
                    <select id={id('status')} value={data.status} onChange={(event) => setData('status', event.target.value)} className={fieldClass}>
                        {statusOptions.map((option) => (
                            <option key={option} value={option} className="capitalize">{option}</option>
                        ))}
                    </select>
                    {errors.status && <p className="mt-1 text-xs text-rose-400">{errors.status}</p>}
                </div>
            )}
            <label className="flex items-center gap-2 self-end pb-2.5 text-sm text-slate-400">
                <input type="checkbox" checked={data.is_head_office} onChange={(event) => setData('is_head_office', event.target.checked)} className="size-4 rounded border-white/20 bg-white/5 text-cyan-400" />
                Head office
            </label>
            <div className="sm:col-span-2">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : branch ? 'Save changes' : 'Create branch'}
                </button>
            </div>
        </form>
    );
}
