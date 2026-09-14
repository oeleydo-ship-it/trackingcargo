import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

interface VerifyEmailProps {
    status?: string;
}

export default function VerifyEmail({ status }: VerifyEmailProps) {
    const [sending, setSending] = useState(false);
    const resend = (event: FormEvent) => {
        event.preventDefault();
        router.post('/email/verification-notification', {}, { onStart: () => setSending(true), onFinish: () => setSending(false) });
    };

    return (
        <AuthLayout title="Verify email" eyebrow="Secure your account" heading="Verify your email" description="Open the verification link sent to your email before entering the operations workspace.">
            {status === 'verification-link-sent' && <div className="mt-6 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">A new verification link has been sent.</div>}
            <form onSubmit={resend} className="mt-8">
                <button type="submit" disabled={sending} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">{sending ? 'Sending…' : 'Resend verification email'}</button>
            </form>
            <button type="button" onClick={() => router.post('/logout')} className="mt-4 w-full rounded-xl border border-white/10 px-4 py-3 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">Sign out</button>
        </AuthLayout>
    );
}
