import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthLayout from '../../layouts/AuthLayout';

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-2 block text-sm font-medium text-slate-300';

export default function Setup() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/setup', {
            onFinish: () => setData((current) => ({ ...current, password: '', password_confirmation: '' })),
        });
    };

    return (
        <AuthLayout
            title="Set up CargoFlow"
            eyebrow="First-run setup"
            heading="Create the superadmin"
            description="This account controls every workspace on the platform. It can only be created once, from here, before anyone else has an account."
        >
            <form onSubmit={submit} className="mt-8 space-y-5">
                <div>
                    <label htmlFor="name" className={labelClass}>Your name</label>
                    <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required autoFocus autoComplete="name" className={fieldClass} />
                    {errors.name && <p role="alert" className="mt-2 text-sm text-rose-400">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="email" className={labelClass}>Email address</label>
                    <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required autoComplete="username" className={fieldClass} placeholder="you@company.com" />
                    {errors.email && <p role="alert" className="mt-2 text-sm text-rose-400">{errors.email}</p>}
                </div>

                <div>
                    <label htmlFor="password" className={labelClass}>Password</label>
                    <input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} required minLength={12} autoComplete="new-password" className={fieldClass} />
                    <p className="mt-2 text-xs text-slate-500">At least 12 characters, with letters and numbers.</p>
                    {errors.password && <p role="alert" className="mt-2 text-sm text-rose-400">{errors.password}</p>}
                </div>

                <div>
                    <label htmlFor="password_confirmation" className={labelClass}>Confirm password</label>
                    <input id="password_confirmation" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} required minLength={12} autoComplete="new-password" className={fieldClass} />
                </div>

                <button type="submit" disabled={processing} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-4 focus:ring-cyan-400/20 disabled:cursor-wait disabled:opacity-60">
                    {processing ? 'Creating account…' : 'Create superadmin and sign in'}
                </button>
            </form>

            <p className="mt-8 text-center text-xs leading-5 text-slate-600">
                After this, the setup page closes for good. More admins are invited from Settings → Platform admins.
            </p>
        </AuthLayout>
    );
}
