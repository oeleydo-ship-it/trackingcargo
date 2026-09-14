import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

interface ForgotPasswordProps {
    status?: string;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/forgot-password');
    };

    return (
        <AuthLayout title="Reset password" eyebrow="Account recovery" heading="Reset your password" description="Enter your account email and we will send a time-limited reset link.">
            {status && <div className="mt-6 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{status}</div>}
            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <label htmlFor="email" className="mb-2 block text-sm font-medium text-slate-300">Email address</label>
                    <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required autoFocus autoComplete="username" className="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10" />
                    {errors.email && <p className="mt-2 text-sm text-rose-400">{errors.email}</p>}
                </div>
                <button type="submit" disabled={processing} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">{processing ? 'Sending…' : 'Send reset link'}</button>
            </form>
            <p className="mt-6 text-center text-sm text-slate-500"><Link href="/login" className="text-cyan-400 hover:text-cyan-300">Return to sign in</Link></p>
        </AuthLayout>
    );
}
