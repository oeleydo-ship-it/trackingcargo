import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

interface ResetPasswordProps {
    email: string;
    token: string;
}

export default function ResetPassword({ email, token }: ResetPasswordProps) {
    const { data, setData, post, processing, errors } = useForm({ email, token, password: '', password_confirmation: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/reset-password', { onFinish: () => setData((current) => ({ ...current, password: '', password_confirmation: '' })) });
    };

    return (
        <AuthLayout title="Choose password" eyebrow="Account recovery" heading="Choose a new password" description={<>Resetting access for <strong className="font-medium text-slate-300">{email}</strong>.</>}>
            <form onSubmit={submit} className="mt-8 space-y-5">
                {(['password', 'password_confirmation'] as const).map((field) => (
                    <div key={field}>
                        <label htmlFor={field} className="mb-2 block text-sm font-medium text-slate-300">{field === 'password' ? 'New password' : 'Confirm password'}</label>
                        <input id={field} type="password" value={data[field]} onChange={(event) => setData(field, event.target.value)} required autoComplete="new-password" className="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10" />
                        {errors[field] && <p className="mt-2 text-sm text-rose-400">{errors[field]}</p>}
                    </div>
                ))}
                <button type="submit" disabled={processing} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">{processing ? 'Updating…' : 'Set new password'}</button>
            </form>
        </AuthLayout>
    );
}
