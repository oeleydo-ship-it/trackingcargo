import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import SuperadminLayout from '../../layouts/SuperadminLayout';
import CountrySelect from '../../components/CountrySelect';

const input = 'mt-1 w-full rounded-lg border border-white/15 bg-slate-900 p-2 text-white';
const button = 'rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50';
type Workspace = { id: number; name: string; code: string; status: string; users_count: number; country_code: string };
type Page<T> = { data: T[]; prev_page_url: string | null; next_page_url: string | null; total: number };
type Registration = { enabled: boolean; requiresApproval: boolean; requiresEmailVerification: boolean };
export default function Workspaces({ workspaces, totals, filters, registration }: { workspaces: Page<Workspace>; totals: Record<string, number>; filters: { q?: string; status?: string }; registration: Registration }) {
    const [creating, setCreating] = useState(false);
    const filter = useForm({ q: filters.q ?? '', status: filters.status ?? '' });
    return <SuperadminLayout title="Superadmin · Workspaces"><Head title="Superadmin workspaces" />
        <RegistrationSwitches registration={registration} pending={totals.pending ?? 0} />
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">{Object.entries(totals).map(([label, total]) => <article key={label} className="rounded-xl border border-white/10 p-4"><p className="text-sm capitalize text-slate-400">{label}</p><p className="mt-2 text-3xl">{total}</p></article>)}</div>
        <div className="mb-5 flex flex-wrap items-end justify-between gap-4">
            <form className="flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); filter.get('/superadmin'); }}>
                <label>Search workspace<input className={input} value={filter.data.q} onChange={e => filter.setData('q', e.target.value)} /></label>
                <label>Status<select className={input} value={filter.data.status} onChange={e => filter.setData('status', e.target.value)}><option value="">All</option>{['active', 'pending', 'suspended', 'inactive'].map(s => <option key={s}>{s}</option>)}</select></label>
                <button className={button}>Filter</button>
            </form>
            <button className={button} onClick={() => setCreating(!creating)}>{creating ? 'Cancel' : 'Create workspace'}</button>
        </div>
        {creating && <CreateWorkspace />}
        <div className="space-y-4">{workspaces.data.map(workspace => <WorkspaceRow key={workspace.id} workspace={workspace} />)}</div>
        {!workspaces.data.length && <p className="py-8 text-slate-400">No workspaces match these filters.</p>}
        <div className="mt-5 flex gap-4">{workspaces.prev_page_url && <Link href={workspaces.prev_page_url}>← Previous</Link>}{workspaces.next_page_url && <Link href={workspaces.next_page_url}>Next →</Link>}</div>
    </SuperadminLayout>;
}

/**
 * The on/off switches for public workspace sign-up at /register. Each click
 * saves straight away; both values are always sent because the endpoint
 * validates the pair together.
 */
function RegistrationSwitches({ registration, pending }: { registration: Registration; pending: number }) {
    const save = (next: Registration) => router.patch('/superadmin/registration', {
        registration_enabled: next.enabled,
        registration_requires_approval: next.requiresApproval,
        registration_requires_email_verification: next.requiresEmailVerification,
    }, { preserveScroll: true });

    return <section className="mb-6 rounded-xl border border-white/10 p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="max-w-xl">
                <h2 className="font-semibold">Workspace sign-up</h2>
                <p className="mt-1 text-sm text-slate-400">
                    {registration.enabled
                        ? <>Anyone can create a company workspace at <a href="/register" target="_blank" rel="noreferrer" className="text-cyan-300">/register</a>.</>
                        : 'Closed. Only you can create workspaces, from the button below.'}
                </p>
                {registration.enabled && pending > 0 && <button type="button" onClick={() => router.get('/superadmin', { status: 'pending' })} className="mt-2 text-sm text-amber-200">{pending} workspace{pending === 1 ? '' : 's'} waiting for approval →</button>}
            </div>
            <div className="space-y-3">
                <Switch label="Allow public sign-up" checked={registration.enabled} onChange={value => save({ ...registration, enabled: value })} />
                <Switch label="New workspaces need my approval" checked={registration.requiresApproval} disabled={!registration.enabled} onChange={value => save({ ...registration, requiresApproval: value })} />
                <Switch label="Require email verification" checked={registration.requiresEmailVerification} disabled={!registration.enabled} onChange={value => save({ ...registration, requiresEmailVerification: value })} />
            </div>
        </div>
        {registration.enabled && registration.requiresEmailVerification && <p className="mt-3 text-sm text-slate-400">New administrators must confirm their email, so mail must be set up under Settings → Platform. No mail provider? Turn off "Require email verification".</p>}
        {registration.enabled && !registration.requiresEmailVerification && <p className="mt-3 text-sm text-amber-200">Email addresses are not checked: anyone can sign up with an address they do not own.{registration.requiresApproval ? ' Review sign-ups carefully before approving them.' : ' With approval also off, every sign-up becomes a working workspace immediately.'}</p>}
    </section>;
}

function Switch({ label, checked, disabled = false, onChange }: { label: string; checked: boolean; disabled?: boolean; onChange: (value: boolean) => void }) {
    return <label className={`flex items-center justify-between gap-4 text-sm ${disabled ? 'opacity-40' : ''}`}>
        <span>{label}</span>
        <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
            className={`relative h-6 w-11 shrink-0 rounded-full transition ${checked ? 'bg-cyan-400' : 'bg-white/15'}`}>
            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition ${checked ? 'left-[22px]' : 'left-0.5'}`} />
        </button>
    </label>;
}

function CreateWorkspace() {
    const form = useForm({ name: '', code: '', slug: '', country_code: 'AE', timezone: 'Asia/Dubai', default_currency: 'AED', branch_name: '', branch_code: '', city: '', admin_name: '', admin_email: '' });
    const labels = { name: 'Workspace name', code: 'Company code (uppercase)', slug: 'URL slug (lowercase)', timezone: 'Timezone', default_currency: 'Currency (3 letters)', branch_name: 'First branch name', branch_code: 'Branch code (uppercase)', city: 'City', admin_name: 'Administrator name', admin_email: 'Administrator email' };
    return <form className="mb-6 rounded-xl border border-cyan-400/30 p-5" onSubmit={e => { e.preventDefault(); form.post('/superadmin/workspaces', { onSuccess: () => form.reset() }); }}>
        <h2 className="font-semibold">Create a workspace and invite its administrator</h2>
        <p className="my-3 text-sm text-slate-400">Creates the first branch, default shipment workflow and a company-only administrator role. Invitations require a running mail queue.</p>
        <div className="grid gap-4 md:grid-cols-2">{Object.entries(labels).map(([key, label]) => <label key={key} className="text-sm">{label}<input required className={input} type={key === 'admin_email' ? 'email' : 'text'} value={form.data[key as keyof typeof labels]} onChange={e => form.setData(key as keyof typeof labels, e.target.value)} />{form.errors[key as keyof typeof labels] && <span className="text-rose-300">{form.errors[key as keyof typeof labels]}</span>}</label>)}
            <label>Country<CountrySelect className={input} value={form.data.country_code} onChange={e => form.setData('country_code', e.target.value)} />{form.errors.country_code && <span className="text-rose-300">{form.errors.country_code}</span>}</label>
        </div>
        <button disabled={form.processing} className={`${button} mt-4`}>Create workspace & invite</button>
    </form>;
}

function WorkspaceRow({ workspace }: { workspace: Workspace }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: workspace.name, status: workspace.status, reason: '' });
    return <article className="rounded-xl border border-white/10 p-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold">{workspace.name} <span className="text-sm text-slate-400">({workspace.code})</span></h2><p className="text-sm text-slate-400">{workspace.status} · {workspace.country_code} · {workspace.users_count} users</p></div>
            <div className="flex gap-4 text-sm text-cyan-300"><Link href={`/superadmin/users?company_id=${workspace.id}`}>Users</Link><button onClick={() => router.post(`/platform/act-as/${workspace.id}`)}>Manage workspace</button><button onClick={() => setEditing(!editing)}>{editing ? 'Cancel' : 'Edit / suspend'}</button></div>
        </div>
        {editing && <form className="mt-5 space-y-3" onSubmit={e => { e.preventDefault(); form.patch(`/superadmin/workspaces/${workspace.id}`, { onSuccess: () => setEditing(false) }); }}>
            <label className="block">Name<input className={input} value={form.data.name} onChange={e => form.setData('name', e.target.value)} required /></label>
            <label className="block">Status<select className={input} value={form.data.status} onChange={e => form.setData('status', e.target.value)}>{['active', 'pending', 'suspended', 'inactive'].map(s => <option key={s}>{s}</option>)}</select></label>
            {workspace.status === 'pending' && <p className="text-sm text-cyan-200">Signed up publicly. Set status to active to approve it — its administrator is then emailed a verification link.</p>}
            <p className="text-sm text-amber-200">Suspending or deactivating blocks company access and revokes user sessions and API tokens. Records are preserved.</p>
            <label className="block">Reason for audit log<input required className={input} value={form.data.reason} onChange={e => form.setData('reason', e.target.value)} /></label>
            {Object.values(form.errors).map(error => <p key={error} className="text-rose-300">{error}</p>)}
            <button disabled={form.processing} className={button}>Confirm changes</button>
        </form>}
    </article>;
}
