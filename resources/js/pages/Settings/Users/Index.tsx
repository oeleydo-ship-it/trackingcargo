import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Role, UserSummary } from '../../../types';

interface UsersIndexProps {
    users: UserSummary[];
    branches: { id: number; name: string }[];
    roles: Role[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const statusColor: Record<string, string> = {
    active: 'text-emerald-300 border-emerald-400/30',
    invited: 'text-cyan-300 border-cyan-400/30',
    suspended: 'text-rose-300 border-rose-400/30',
    inactive: 'text-slate-400 border-white/10',
};

export default function UsersIndex({ users, branches, roles }: UsersIndexProps) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        phone: '',
        branch_id: '' as number | '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/users', {
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const assignRole = (user: UserSummary, roleId: number) => {
        if (roleId === 0) {
            return;
        }
        router.post(`/settings/users/${user.id}/roles`, { role_id: roleId }, { preserveScroll: true });
    };

    const revokeRole = (user: UserSummary, role: Role) => {
        router.delete(`/settings/users/${user.id}/roles/${role.id}`, { preserveScroll: true });
    };

    const toggleStatus = (user: UserSummary) => {
        const action = user.status === 'suspended' ? 'reactivate' : 'suspend';
        router.post(`/settings/users/${user.id}/${action}`, {}, { preserveScroll: true });
    };

    return (
        <SettingsLayout title="Users">
            <Head title="Users" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{users.length} user{users.length === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'Invite user'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2">
                    <div>
                        <label htmlFor="name" className={labelClass}>Name</label>
                        <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                        {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                    </div>
                    <div>
                        <label htmlFor="email" className={labelClass}>Email</label>
                        <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required className={fieldClass} />
                        {errors.email && <p className="mt-1 text-xs text-rose-400">{errors.email}</p>}
                    </div>
                    <div>
                        <label htmlFor="phone" className={labelClass}>Phone</label>
                        <input id="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="branch_id" className={labelClass}>Branch</label>
                        <select id="branch_id" value={data.branch_id} onChange={(event) => setData('branch_id', event.target.value ? Number(event.target.value) : '')} className={fieldClass}>
                            <option value="">Company-wide</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>{branch.name}</option>
                            ))}
                        </select>
                        {errors.branch_id && <p className="mt-1 text-xs text-rose-400">{errors.branch_id}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                            {processing ? 'Sending…' : 'Send invitation'}
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Name</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Roles</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {users.map((user) => (
                            <tr key={user.id}>
                                <td className="px-4 py-3">
                                    <p className="font-medium">{user.name}</p>
                                    <p className="text-xs text-slate-500">{user.email}</p>
                                </td>
                                <td className="px-4 py-3 text-slate-400">{user.branch?.name ?? 'Company-wide'}</td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {user.roles.map((role) => (
                                            <span key={role.id} className="flex items-center gap-1.5 rounded-full border border-white/10 px-2 py-0.5 text-xs text-slate-300">
                                                {role.name}
                                                <button onClick={() => revokeRole(user, role)} className="text-slate-500 hover:text-rose-400" aria-label={`Revoke ${role.name}`}>×</button>
                                            </span>
                                        ))}
                                        <select
                                            defaultValue={0}
                                            onChange={(event) => {
                                                assignRole(user, Number(event.target.value));
                                                event.target.value = '0';
                                            }}
                                            className="rounded-full border border-dashed border-white/15 bg-transparent px-2 py-0.5 text-xs text-slate-500"
                                        >
                                            <option value={0}>+ Add role</option>
                                            {roles.filter((role) => !user.roles.some((assigned) => assigned.id === role.id)).map((role) => (
                                                <option key={role.id} value={role.id}>{role.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                </td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[user.status] ?? 'border-white/10 text-slate-400'}`}>{user.status}</span></td>
                                <td className="px-4 py-3 text-right">
                                    {user.status !== 'invited' && (
                                        <button onClick={() => toggleStatus(user)} className="text-xs text-slate-400 hover:text-white">
                                            {user.status === 'suspended' ? 'Reactivate' : 'Suspend'}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {users.length === 0 && (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No users yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </SettingsLayout>
    );
}
