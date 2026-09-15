import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CountrySelect from '../../components/CountrySelect';
import AuthLayout from '../../layouts/AuthLayout';

interface RegisterProps {
    /** Whether a superadmin must approve the workspace before it can be used. */
    requiresApproval: boolean;
    /** Whether the new administrator must confirm their email address. */
    requiresEmailVerification: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-2 block text-sm font-medium text-slate-300';

export default function Register({ requiresApproval, requiresEmailVerification }: RegisterProps) {
    const { data, setData, post, processing, errors } = useForm({
        company_name: '',
        country_code: '',
        city: '',
        name: '',
        email: '',
        phone: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/register', {
            onFinish: () => setData((current) => ({ ...current, password: '', password_confirmation: '' })),
        });
    };

    const error = (message?: string) => (message ? <p role="alert" className="mt-2 text-sm text-rose-400">{message}</p> : null);

    return (
        <AuthLayout
            title="Create a workspace"
            eyebrow="Get started"
            heading="Create your company workspace"
            description={requiresApproval
                ? 'Tell us about your company. Your workspace will be reviewed before you can sign in.'
                : requiresEmailVerification
                    ? 'Tell us about your company. You will be signed in straight away and asked to confirm your email.'
                    : 'Tell us about your company. You will be signed in straight away.'}
        >
            <form onSubmit={submit} className="mt-8 space-y-5">
                <fieldset className="space-y-4">
                    <legend className="mb-1 text-xs font-bold uppercase tracking-widest text-cyan-400">Company</legend>
                    <div>
                        <label htmlFor="company_name" className={labelClass}>Company name</label>
                        <input id="company_name" value={data.company_name} onChange={(event) => setData('company_name', event.target.value)} required autoFocus autoComplete="organization" className={fieldClass} />
                        {error(errors.company_name)}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="country_code" className={labelClass}>Country</label>
                            <CountrySelect id="country_code" value={data.country_code} onChange={(event) => setData('country_code', event.target.value)} required className={fieldClass} />
                            {error(errors.country_code)}
                        </div>
                        <div>
                            <label htmlFor="city" className={labelClass}>City</label>
                            <input id="city" value={data.city} onChange={(event) => setData('city', event.target.value)} required autoComplete="address-level2" className={fieldClass} />
                            {error(errors.city)}
                        </div>
                    </div>
                </fieldset>

                <fieldset className="space-y-4">
                    <legend className="mb-1 text-xs font-bold uppercase tracking-widest text-cyan-400">Your administrator account</legend>
                    <div>
                        <label htmlFor="name" className={labelClass}>Your name</label>
                        <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required autoComplete="name" className={fieldClass} />
                        {error(errors.name)}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="email" className={labelClass}>Work email</label>
                            <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} required autoComplete="username" className={fieldClass} placeholder="you@company.com" />
                            {error(errors.email)}
                        </div>
                        <div>
                            <label htmlFor="phone" className={labelClass}>Phone <span className="text-slate-500">(optional)</span></label>
                            <input id="phone" type="tel" value={data.phone} onChange={(event) => setData('phone', event.target.value)} autoComplete="tel" className={fieldClass} />
                            {error(errors.phone)}
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="password" className={labelClass}>Password</label>
                            <input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} required minLength={12} autoComplete="new-password" className={fieldClass} />
                            {error(errors.password)}
                        </div>
                        <div>
                            <label htmlFor="password_confirmation" className={labelClass}>Confirm password</label>
                            <input id="password_confirmation" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} required minLength={12} autoComplete="new-password" className={fieldClass} />
                        </div>
                    </div>
                    <p className="text-xs text-slate-500">At least 12 characters, with letters and numbers.</p>
                </fieldset>

                <label className="flex items-start gap-3 text-sm text-slate-400">
                    <input type="checkbox" checked={data.terms} onChange={(event) => setData('terms', event.target.checked)} required className="mt-0.5 size-4 rounded border-white/20 bg-white/5 text-cyan-400 focus:ring-cyan-400/20" />
                    I am authorised to create this workspace on behalf of the company.
                </label>
                {error(errors.terms)}

                <button type="submit" disabled={processing} className="w-full rounded-xl bg-cyan-400 px-4 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 focus:outline-none focus:ring-4 focus:ring-cyan-400/20 disabled:cursor-wait disabled:opacity-60">
                    {processing ? 'Creating workspace…' : 'Create workspace'}
                </button>
            </form>

            <p className="mt-8 text-center text-sm text-slate-400">
                Already have an account? <Link href="/login" className="font-semibold text-cyan-400 hover:text-cyan-300">Sign in</Link>
            </p>
        </AuthLayout>
    );
}
