import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

interface LoginForm {
    email: string;
    password: string;
    remember: boolean;
    code: string;
}

interface LoginProps {
    status?: string;
}

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors } = useForm<LoginForm>({
        email: '',
        code: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/login', { onFinish: () => setData('password', '') });
    };

    return (
        <AuthLayout title="Sign in" eyebrow="Secure operations portal" heading="Welcome back" description="Sign in with your CargoFlow account.">
                        {status && <div className="mt-6 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{status}</div>}
                        <form onSubmit={submit} className="mt-8 space-y-5">
                            <div>
                                <label htmlFor="email" className="mb-2 block text-sm font-medium text-slate-300">Email address</label>
                                <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required autoFocus autoComplete="username" className="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10" placeholder="you@company.com" />
                                {errors.email && <p className="mt-2 text-sm text-rose-400">{errors.email}</p>}
                            </div>
                            <div>
                                <div className="mb-2 flex items-center justify-between"><label htmlFor="password" className="block text-sm font-medium text-slate-300">Password</label><Link href="/forgot-password" className="text-xs text-cyan-400 hover:text-cyan-300">Forgot password?</Link></div>
                                <input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} required autoComplete="current-password" className="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10" />
                                {errors.password && <p className="mt-2 text-sm text-rose-400">{errors.password}</p>}
                            </div>
                            <label className="flex items-center gap-3 text-sm text-slate-400">
                                <span className="sr-only">Remember this device</span>
                                <input type="checkbox" checked={data.remember} onChange={(event) => setData('remember', event.target.checked)} className="size-4 rounded border-white/20 bg-white/5 text-cyan-400 focus:ring-cyan-400/20" />
                                Keep me signed in on this device
                            </label>
                            <div>
                                <label htmlFor="two-factor-code" className="mb-2 block text-sm text-slate-300">Authenticator or recovery code (if enabled)</label>
                                <input id="two-factor-code" autoComplete="one-time-code" value={data.code} onChange={(event) => setData('code', event.target.value)} className="w-full rounded-xl border border-white/10 bg-white/5 p-3 text-white" />
                                {errors.code && <p role="alert" className="mt-2 text-sm text-rose-400">{errors.code}</p>}
                            </div>
                            <button type="submit" disabled={processing} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-4 focus:ring-cyan-400/20 disabled:cursor-wait disabled:opacity-60">
                                {processing ? 'Signing in…' : 'Sign in to CargoFlow'}
                            </button>
                        </form>
                        <p className="mt-8 text-center text-xs leading-5 text-slate-600">Access is monitored and security-relevant actions are written to the immutable audit log.</p>
        </AuthLayout>
    );
}
