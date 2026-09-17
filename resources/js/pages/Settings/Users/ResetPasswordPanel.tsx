import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { UserSummary } from '../../../types';

interface ResetPasswordPanelProps {
    user: UserSummary;
    onDone: () => void;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

/**
 * The two ways to get someone back into their account, next to the user they
 * belong to: hand them a password now (for a workspace with no mail set up,
 * or a driver standing at the counter), or mail the usual reset link so they
 * pick their own and nobody else ever knows it.
 */
export default function ResetPasswordPanel({ user, onDone }: ResetPasswordPanelProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        method: 'password' as 'password' | 'email',
        password: '',
        password_confirmation: '',
    });

    const settingPassword = data.method === 'password';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/settings/users/${user.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <fieldset className="sm:col-span-2">
                <legend className={labelClass}>Reset the password for {user.name}</legend>
                <div className="grid gap-3 sm:grid-cols-2">
                    {([
                        ['password', 'Set a password now', 'They can sign in immediately. No email is sent — hand the password over yourself.'],
                        ['email', 'Email a reset link', 'They choose their own password. Needs working mail settings.'],
                    ] as const).map(([value, title, hint]) => (
                        <label
                            key={value}
                            className={`flex cursor-pointer gap-3 rounded-xl border p-3 transition ${data.method === value ? 'border-cyan-400/50 bg-cyan-400/10' : 'border-white/10 hover:border-white/20'}`}
                        >
                            <input
                                type="radio"
                                name={`reset-method-${user.id}`}
                                value={value}
                                checked={data.method === value}
                                onChange={() => setData((current) => ({ ...current, method: value, password: '', password_confirmation: '' }))}
                                className="mt-1"
                            />
                            <span>
                                <span className="block text-sm font-medium text-white">{title}</span>
                                <span className="block text-xs text-slate-400">{hint}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {errors.method && <p className="mt-2 text-xs text-rose-400">{errors.method}</p>}
            </fieldset>

            {settingPassword && (
                <>
                    <div>
                        <label htmlFor={`reset-password-${user.id}`} className={labelClass}>New password</label>
                        <input
                            id={`reset-password-${user.id}`}
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            required
                            minLength={12}
                            autoComplete="new-password"
                            className={fieldClass}
                        />
                        <p className="mt-1 text-xs text-slate-500">At least 12 characters, with letters and numbers.</p>
                        {errors.password && <p className="mt-1 text-xs text-rose-400">{errors.password}</p>}
                    </div>
                    <div>
                        <label htmlFor={`reset-confirmation-${user.id}`} className={labelClass}>Confirm password</label>
                        <input
                            id={`reset-confirmation-${user.id}`}
                            type="password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            required
                            minLength={12}
                            autoComplete="new-password"
                            className={fieldClass}
                        />
                    </div>
                </>
            )}

            <div className="flex items-center gap-3 sm:col-span-2">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {settingPassword
                        ? (processing ? 'Saving…' : 'Set password')
                        : (processing ? 'Sending…' : 'Send reset link')}
                </button>
                <button type="button" onClick={onDone} className="text-xs text-slate-400 hover:text-white">Cancel</button>
                {settingPassword && (
                    <p className="text-xs text-slate-500">Signs them out everywhere else.</p>
                )}
            </div>
        </form>
    );
}
