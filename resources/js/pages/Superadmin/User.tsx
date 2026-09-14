import { Head, useForm } from '@inertiajs/react';
import SuperadminLayout from '../../layouts/SuperadminLayout';

type ManagedUser = { id: number; name: string; email: string; phone: string | null; branch_id: number | null; status: string; two_factor_confirmed_at: string | null };
const input = 'mt-1 block w-full rounded-lg border border-white/15 bg-slate-900 p-2';
const button = 'rounded-lg bg-cyan-400 px-4 py-2 text-sm text-slate-950 disabled:opacity-50';
export default function User({ managedUser: user, company, branches, roles, roleIds }: { managedUser: ManagedUser; company: { name: string } | null; branches: { id: number; name: string }[]; roles: { id: number; name: string }[]; roleIds: number[] }) {
    const form = useForm({ name: user.name, phone: user.phone ?? '', branch_id: user.branch_id?.toString() ?? '', roles: roleIds, reason: '' });
    const action = useForm({ reason: '' });
    return <SuperadminLayout title={`Manage ${user.name}`}><Head title="Manage workspace user" />
        <p className="mb-6 text-slate-400">{company?.name} · {user.email} · {user.status} · 2FA {user.two_factor_confirmed_at ? 'enabled' : 'not enabled'}</p>
        <div className="grid gap-6 lg:grid-cols-2">
            <form className="space-y-4 rounded-xl border border-white/10 p-5" onSubmit={e => { e.preventDefault(); form.patch(`/superadmin/users/${user.id}`, { onSuccess: () => form.reset('reason') }); }}>
                <h2 className="font-semibold">Profile and access</h2>
                <label className="block">Name<input required className={input} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></label>
                <label className="block">Phone<input type="tel" className={input} value={form.data.phone} onChange={e => form.setData('phone', e.target.value)} /></label>
                <label className="block">Branch<select className={input} value={form.data.branch_id} onChange={e => form.setData('branch_id', e.target.value)}><option value="">Company-wide (no branch restriction)</option>{branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
                <fieldset><legend className="mb-2">Company roles</legend>{roles.map(role => <label key={role.id} className="mb-2 flex gap-2"><input type="checkbox" checked={form.data.roles.includes(role.id)} onChange={e => form.setData('roles', e.target.checked ? [...form.data.roles, role.id] : form.data.roles.filter(id => id !== role.id))} />{role.name}</label>)}</fieldset>
                <label className="block">Reason for audit log<input required className={input} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} /></label>
                <p className="text-sm text-amber-200">Saving revokes current sessions and API tokens. Company membership and platform-admin privileges cannot be changed here.</p>
                {Object.values(form.errors).map(error => <p key={error} className="text-rose-300">{error}</p>)}
                <button disabled={form.processing} className={button}>Save access changes</button>
            </form>
            <form className="space-y-4 rounded-xl border border-white/10 p-5" onSubmit={e => e.preventDefault()}>
                <h2 className="font-semibold">Account controls</h2>
                <label className="block">Reason for audit log<input required className={input} value={action.data.reason} onChange={e => action.setData('reason', e.target.value)} /></label>
                <p className="text-sm text-slate-400">Suspend blocks sign-in and revokes access. Activation never bypasses invitation acceptance or company suspension. Password reset sends a link; passwords and 2FA secrets are never displayed.</p>
                <div className="flex flex-wrap gap-3">{[['suspend', 'Suspend account'], ['activate', 'Activate account'], ['revoke', 'Revoke sessions & tokens'], ['invite', 'Resend invitation'], ['reset-password', 'Send password reset']].map(([key, label]) => <button key={key} disabled={action.processing || !action.data.reason.trim()} className={button} onClick={() => action.post(`/superadmin/users/${user.id}/${key}`)}>{label}</button>)}</div>
                {Object.values(action.errors).map(error => <p key={error} className="text-rose-300">{error}</p>)}
            </form>
        </div>
    </SuperadminLayout>;
}
