import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { SharedPageProps } from '../../types';

const field = 'mt-1 w-full rounded-xl border border-white/10 bg-white/5 p-3 text-white';
const button = 'rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50';
const panel = 'space-y-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6';
type Profile = { name: string; email: string; phone: string | null };
type Props = {
    profile: Profile; twoFactorEnabled: boolean; setupSecret: string | null; setupQr: string | null;
    recoveryCodes: string[]; newToken: string | null;
    tokens: { id: number; name: string; created_at: string; last_used_at: string | null; expires_at: string | null }[];
    sessions: { id: string; ip: string; agent: string; lastActivity: number; current: boolean }[];
};

function Action({ url, label, enabled, method = 'post', kind, profile }: { url: string; label: string; enabled: boolean; method?: 'post' | 'patch' | 'delete'; kind?: 'profile' | 'token' | 'confirm'; profile?: Profile }) {
    const { auth } = usePage<SharedPageProps>().props;
    const { data, setData, post, patch, delete: destroy, errors, processing, reset } = useForm({ password: '', code: '', setup_code: '', name: profile?.name ?? '', phone: profile?.phone ?? '', new_password: '', new_password_confirmation: '', days: 30 });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const send = method === 'patch' ? patch : method === 'delete' ? destroy : post;
        send(url, { preserveScroll: true, onSuccess: () => reset('password', 'code', 'setup_code', 'new_password', 'new_password_confirmation') });
    };
    return <form onSubmit={submit} className="space-y-3 border-t border-white/10 pt-4">
        <input aria-label="Account email" type="text" name="username" autoComplete="username" value={auth.user?.email ?? ''} readOnly tabIndex={-1} className="sr-only" />
        {kind === 'profile' && <>
            <label className="block text-sm">Name<input required className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" /></label>
            <label className="block text-sm">Phone<input type="tel" name="phone" className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} autoComplete="tel" /></label>
            <label className="block text-sm">New password (optional, at least 12 characters with letters and numbers)<input type="password" autoComplete="new-password" className={field} value={data.new_password} onChange={(e) => setData('new_password', e.target.value)} /></label>
            <label className="block text-sm">Confirm new password<input type="password" autoComplete="new-password" className={field} value={data.new_password_confirmation} onChange={(e) => setData('new_password_confirmation', e.target.value)} /></label>
            <p className="text-xs text-slate-400">Changing your password revokes API tokens and signs out other database sessions.</p>
        </>}
        {kind === 'token' && <>
            <label className="block text-sm">Token name<input required maxLength={120} className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Warehouse integration" /></label>
            <label className="block text-sm">Expires in days<input required type="number" min={1} max={365} className={field} value={data.days} onChange={(e) => setData('days', Number(e.target.value))} /></label>
        </>}
        {kind === 'confirm' && <label className="block text-sm">Code from your authenticator<input required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" className={field} value={data.setup_code} onChange={(e) => setData('setup_code', e.target.value)} /></label>}
        <label className="block text-sm">Current password<input required type="password" autoComplete="current-password" className={field} value={data.password} onChange={(e) => setData('password', e.target.value)} /></label>
        {enabled && <label className="block text-sm">Unused authenticator or recovery code<input required autoComplete="one-time-code" className={field} value={data.code} onChange={(e) => setData('code', e.target.value)} /><span className="mt-1 block text-xs text-slate-400">After using a code, wait for the next code before another security action.</span></label>}
        {Object.entries(errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-rose-300">{error}</p>)}
        <button disabled={processing} className={button}>{processing ? 'Saving…' : label}</button>
    </form>;
}

export default function Account(props: Props) {
    return <AppLayout title="My account"><Head title="My account" />
        <div className="grid gap-6 xl:grid-cols-2">
            <section className={panel}><h2 className="text-lg font-semibold">Profile and password</h2><p className="text-sm text-slate-400">{props.profile.email}</p><Action url="/account" method="patch" label="Save profile" kind="profile" profile={props.profile} enabled={props.twoFactorEnabled} /></section>
            <section className={panel}><h2 className="text-lg font-semibold">Two-factor authentication</h2><p className="text-sm text-slate-400">{props.twoFactorEnabled ? 'Enabled. Your password and a one-time code protect sign-in.' : 'Add an authenticator app to protect your account.'}</p>
                {props.recoveryCodes.length > 0 && <div role="status" className="rounded-xl border border-amber-300/30 p-4"><p className="mb-3 text-sm text-amber-200">Save these recovery codes somewhere private now. Each works once; this list is shown once.</p><div className="space-y-1 font-mono text-sm">{props.recoveryCodes.map((code) => <p key={code}>{code}</p>)}</div></div>}
                {props.setupSecret && !props.twoFactorEnabled ? <>
                    <p className="text-sm">Scan this QR code in your authenticator app, or enter the setup key. Setup expires in 10 minutes.</p>
                    {props.setupQr && <img src={props.setupQr} alt="Authenticator enrollment QR code" width={220} height={220} className="bg-white" />}
                    <p className="break-all font-mono text-sm">{props.setupSecret}</p>
                    <Action url="/account/security/confirm" label="Confirm and enable 2FA" kind="confirm" enabled={false} />
                    <Action url="/account/security/cancel" label="Cancel setup" enabled={false} />
                </> : props.twoFactorEnabled ? <>
                    <Action url="/account/security/recovery" label="Replace recovery codes" enabled />
                    <Action url="/account/security/disable" label="Disable 2FA" enabled />
                </> : <Action url="/account/security/enable" label="Set up authenticator" enabled={false} />}
                <p className="text-xs text-slate-400">Enabling 2FA revokes existing API tokens and other database sessions.</p>
            </section>
            <section className={panel}><h2 className="text-lg font-semibold">API tokens</h2>
                {props.newToken && <div role="status" className="rounded-xl border border-amber-300/30 p-4"><p className="mb-2 text-sm">Copy this token now. It is shown once.</p><code className="break-all text-sm">{props.newToken}</code></div>}
                <p className="text-sm text-slate-400">Tokens inherit your current account permissions. Use them as Bearer tokens with /api/v1/me.</p>
                <Action url="/account/tokens" label="Create token" kind="token" enabled={props.twoFactorEnabled} />
                {props.tokens.map((token) => <details key={token.id} className="rounded-xl border border-white/10 p-3"><summary className="cursor-pointer">{token.name} — {token.expires_at ? `expires ${new Date(token.expires_at).toLocaleDateString()}` : 'no expiry'}</summary><p className="mt-2 text-xs text-slate-400">Last used: {token.last_used_at ? new Date(token.last_used_at).toLocaleString() : 'Never'}</p><Action url={`/account/tokens/${token.id}`} method="delete" label="Revoke token" enabled={props.twoFactorEnabled} /></details>)}
                {props.tokens.length === 0 && <p className="text-sm text-slate-400">No API tokens.</p>}
            </section>
            <section className={panel}><h2 className="text-lg font-semibold">Sign-in sessions</h2>
                {props.sessions.map((session) => <div key={session.id} className="rounded-xl border border-white/10 p-3"><p className="text-sm">{session.current ? 'This session' : 'Other session'} · {session.ip}</p><p className="break-words text-xs text-slate-400">{session.agent}</p><p className="text-xs text-slate-400">Last active {new Date(session.lastActivity * 1000).toLocaleString()}</p></div>)}
                {props.sessions.length === 0 && <p className="text-sm text-slate-400">Session listing requires database-backed sessions.</p>}
                <Action url="/account/security/sessions" label="Sign out other sessions" enabled={props.twoFactorEnabled} />
            </section>
        </div>
    </AppLayout>;
}
