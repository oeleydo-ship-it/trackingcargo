import { Head, Link, useForm } from '@inertiajs/react';
import SuperadminLayout from '../../layouts/SuperadminLayout';

type User = { id: number; name: string; email: string; status: string; company: { name: string } | null; two_factor_confirmed_at: string | null };
export default function Users({ users, companies, filters }: { users: { data: User[]; prev_page_url: string | null; next_page_url: string | null }; companies: { id: number; name: string }[]; filters: { q?: string; company_id?: string; status?: string } }) {
    const form = useForm({ q: filters.q ?? '', company_id: filters.company_id ?? '', status: filters.status ?? '' });
    const input = 'mt-1 block rounded-lg border border-white/15 bg-slate-900 p-2';
    return <SuperadminLayout title="Superadmin · All workspace users"><Head title="Workspace users" />
        <form className="mb-6 flex flex-wrap items-end gap-4" onSubmit={e => { e.preventDefault(); form.get('/superadmin/users'); }}>
            <label>Name or email<input className={input} value={form.data.q} onChange={e => form.setData('q', e.target.value)} /></label>
            <label>Workspace<select className={input} value={form.data.company_id} onChange={e => form.setData('company_id', e.target.value)}><option value="">All workspaces</option>{companies.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
            <label>Status<select className={input} value={form.data.status} onChange={e => form.setData('status', e.target.value)}><option value="">All</option>{['active', 'invited', 'suspended', 'inactive'].map(s => <option key={s}>{s}</option>)}</select></label>
            <button className="rounded-lg bg-cyan-400 px-4 py-2 text-slate-950">Filter</button>
        </form>
        <p className="mb-4 text-sm text-slate-400">To invite additional staff, choose Manage workspace → Settings → Users. Platform administrators are managed separately.</p>
        <div className="overflow-x-auto rounded-xl border border-white/10"><table className="w-full text-left text-sm"><thead><tr>{['User', 'Workspace', 'Status', '2FA', 'Control'].map(h => <th key={h} className="p-4">{h}</th>)}</tr></thead><tbody>{users.data.map(u => <tr key={u.id} className="border-t border-white/10"><td className="p-4">{u.name}<div className="text-slate-400">{u.email}</div></td><td className="p-4">{u.company?.name ?? 'Unavailable'}</td><td className="p-4">{u.status}</td><td className="p-4">{u.two_factor_confirmed_at ? 'Enabled' : 'Not enabled'}</td><td className="p-4"><Link className="text-cyan-300" href={`/superadmin/users/${u.id}`}>Manage user</Link></td></tr>)}</tbody></table></div>
        {!users.data.length && <p className="py-6">No users match these filters.</p>}
        <div className="mt-5 flex gap-4">{users.prev_page_url && <Link href={users.prev_page_url}>← Previous</Link>}{users.next_page_url && <Link href={users.next_page_url}>Next →</Link>}</div>
    </SuperadminLayout>;
}
