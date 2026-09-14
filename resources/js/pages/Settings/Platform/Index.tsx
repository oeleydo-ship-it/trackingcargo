import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';

interface PlatformSettingsProps {
    settings: {
        stripe_subscriptions: Array<{
            id: string;
            status: string;
            customer: string;
            created: number;
            current_period_end: number;
            quantity: number;
            price_id: string;
            price_currency: string;
            unit_amount: number | null;
        }>;
        site_name: string;
        support_email: string | null;
        default_timezone: string;
        default_currency: string;
        logo_url: string | null;
        favicon_url: string | null;
        smtp_host: string | null;
        smtp_port: number | null;
        smtp_username: string | null;
        smtp_has_password: boolean;
        smtp_encryption: string | null;
        smtp_from_address: string | null;
        smtp_from_name: string | null;
        stripe_publishable_key: string | null;
        stripe_has_secret_key: boolean;
        stripe_has_webhook_secret: boolean;
    };
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';
const cardClass = 'rounded-2xl border border-white/10 bg-white/[0.035] p-6';
const buttonClass = 'rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60';

export default function PlatformSettingsIndex({ settings }: PlatformSettingsProps) {
    return (
        <SettingsLayout title="Platform">
            <Head title="Platform settings" />
            <p className="mb-6 max-w-2xl text-sm text-slate-500">
                Configuration for the whole platform, not any one company: branding, outgoing SMTP and payment gateway controls.
            </p>

            <div className="grid gap-6 xl:grid-cols-2">
                <GeneralCard settings={settings} />
                <BrandingCard settings={settings} />
                <SmtpCard settings={settings} />
                <StripeCard settings={settings} />
                <StripeSubscriptionsCard settings={settings} />
            </div>
        </SettingsLayout>
    );
}

function GeneralCard({ settings }: PlatformSettingsProps) {
    const { data, setData, patch, processing, errors } = useForm({
        site_name: settings.site_name,
        support_email: settings.support_email ?? '',
        default_timezone: settings.default_timezone,
        default_currency: settings.default_currency,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/platform/general');
    };

    return (
        <article className={cardClass}>
            <p className="text-sm font-semibold">General</p>
            <p className="mt-1 text-xs text-slate-500">The name and defaults shown across the platform.</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label htmlFor="site_name" className={labelClass}>Site name</label>
                    <input id="site_name" value={data.site_name} onChange={(event) => setData('site_name', event.target.value)} required className={fieldClass} />
                    {errors.site_name && <p className="mt-1 text-xs text-rose-400">{errors.site_name}</p>}
                </div>
                <div>
                    <label htmlFor="support_email" className={labelClass}>Support email</label>
                    <input id="support_email" type="email" value={data.support_email} onChange={(event) => setData('support_email', event.target.value)} className={fieldClass} />
                    {errors.support_email && <p className="mt-1 text-xs text-rose-400">{errors.support_email}</p>}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="default_timezone" className={labelClass}>Default timezone</label>
                        <input id="default_timezone" value={data.default_timezone} onChange={(event) => setData('default_timezone', event.target.value)} required className={fieldClass} />
                        {errors.default_timezone && <p className="mt-1 text-xs text-rose-400">{errors.default_timezone}</p>}
                    </div>
                    <div>
                        <label htmlFor="default_currency" className={labelClass}>Default currency</label>
                        <input id="default_currency" value={data.default_currency} onChange={(event) => setData('default_currency', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} />
                        {errors.default_currency && <p className="mt-1 text-xs text-rose-400">{errors.default_currency}</p>}
                    </div>
                </div>
                <button type="submit" disabled={processing} className={buttonClass}>{processing ? 'Saving…' : 'Save general settings'}</button>
            </form>
        </article>
    );
}

function BrandingCard({ settings }: PlatformSettingsProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{ logo: File | null; favicon: File | null }>({ logo: null, favicon: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/platform/branding', { forceFormData: true, onSuccess: () => reset() });
    };

    const remove = (field: 'remove_logo' | 'remove_favicon') => {
        router.post('/settings/platform/branding', { [field]: true }, { preserveScroll: true });
    };

    return (
        <article className={cardClass}>
            <p className="text-sm font-semibold">Branding</p>
            <p className="mt-1 text-xs text-slate-500">Shown in the sidebar, the login page, and public tracking.</p>

            <div className="mt-4 flex items-center gap-4">
                {settings.logo_url ? (
                    <img src={settings.logo_url} alt="Current logo" className="size-14 rounded-xl border border-white/10 object-contain" />
                ) : (
                    <span className="grid size-14 place-items-center rounded-xl border border-dashed border-white/15 text-xs text-slate-600">No logo</span>
                )}
                {settings.logo_url && (
                    <button type="button" onClick={() => remove('remove_logo')} className="text-xs text-rose-400 hover:text-rose-300">Remove logo</button>
                )}
            </div>

            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label htmlFor="logo" className={labelClass}>Upload a new logo</label>
                    <input id="logo" type="file" accept="image/*" onChange={(event) => setData('logo', event.target.files?.[0] ?? null)} className={fieldClass} />
                    {errors.logo && <p className="mt-1 text-xs text-rose-400">{errors.logo}</p>}
                </div>
                <div>
                    <label htmlFor="favicon" className={labelClass}>Upload a new favicon</label>
                    <input id="favicon" type="file" accept="image/*" onChange={(event) => setData('favicon', event.target.files?.[0] ?? null)} className={fieldClass} />
                    {errors.favicon && <p className="mt-1 text-xs text-rose-400">{errors.favicon}</p>}
                    {settings.favicon_url && (
                        <button type="button" onClick={() => remove('remove_favicon')} className="mt-1.5 text-xs text-rose-400 hover:text-rose-300">Remove favicon</button>
                    )}
                </div>
                <button type="submit" disabled={processing || (!data.logo && !data.favicon)} className={buttonClass}>{processing ? 'Uploading…' : 'Upload'}</button>
            </form>
        </article>
    );
}

function SmtpCard({ settings }: PlatformSettingsProps) {
    const { data, setData, patch, processing, errors } = useForm({
        smtp_host: settings.smtp_host ?? '',
        smtp_port: settings.smtp_port ?? 587,
        smtp_username: settings.smtp_username ?? '',
        smtp_password: '',
        smtp_encryption: settings.smtp_encryption ?? 'tls',
        smtp_from_address: settings.smtp_from_address ?? '',
        smtp_from_name: settings.smtp_from_name ?? '',
    });
    const test = useForm({ to: settings.support_email ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/platform/smtp');
    };

    const sendTest = (event: FormEvent) => {
        event.preventDefault();
        test.post('/settings/platform/smtp/test', { preserveScroll: true });
    };

    return (
        <article className={cardClass}>
            <p className="text-sm font-semibold">SMTP</p>
            <p className="mt-1 text-xs text-slate-500">The outgoing mail server every company's notifications and invitations go through.</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div className="grid grid-cols-3 gap-3">
                    <div className="col-span-2">
                        <label htmlFor="smtp_host" className={labelClass}>Host</label>
                        <input id="smtp_host" value={data.smtp_host} onChange={(event) => setData('smtp_host', event.target.value)} required placeholder="smtp.mailgun.org" className={fieldClass} />
                        {errors.smtp_host && <p className="mt-1 text-xs text-rose-400">{errors.smtp_host}</p>}
                    </div>
                    <div>
                        <label htmlFor="smtp_port" className={labelClass}>Port</label>
                        <input id="smtp_port" type="number" value={data.smtp_port} onChange={(event) => setData('smtp_port', Number(event.target.value))} required className={fieldClass} />
                        {errors.smtp_port && <p className="mt-1 text-xs text-rose-400">{errors.smtp_port}</p>}
                    </div>
                </div>
                <div>
                    <label htmlFor="smtp_username" className={labelClass}>Username</label>
                    <input id="smtp_username" value={data.smtp_username} onChange={(event) => setData('smtp_username', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor="smtp_password" className={labelClass}>Password{settings.smtp_has_password ? ' (leave blank to keep the current one)' : ''}</label>
                    <input id="smtp_password" type="password" value={data.smtp_password} onChange={(event) => setData('smtp_password', event.target.value)} placeholder={settings.smtp_has_password ? '••••••••' : ''} className={fieldClass} />
                    {errors.smtp_password && <p className="mt-1 text-xs text-rose-400">{errors.smtp_password}</p>}
                </div>
                <div>
                    <label htmlFor="smtp_encryption" className={labelClass}>Encryption</label>
                    <select id="smtp_encryption" value={data.smtp_encryption} onChange={(event) => setData('smtp_encryption', event.target.value)} className={fieldClass}>
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="">None</option>
                    </select>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="smtp_from_address" className={labelClass}>From address</label>
                        <input id="smtp_from_address" type="email" value={data.smtp_from_address} onChange={(event) => setData('smtp_from_address', event.target.value)} required className={fieldClass} />
                        {errors.smtp_from_address && <p className="mt-1 text-xs text-rose-400">{errors.smtp_from_address}</p>}
                    </div>
                    <div>
                        <label htmlFor="smtp_from_name" className={labelClass}>From name</label>
                        <input id="smtp_from_name" value={data.smtp_from_name} onChange={(event) => setData('smtp_from_name', event.target.value)} className={fieldClass} />
                    </div>
                </div>
                <button type="submit" disabled={processing} className={buttonClass}>{processing ? 'Saving…' : 'Save SMTP settings'}</button>
            </form>

            <form onSubmit={sendTest} className="mt-4 flex items-end gap-2 border-t border-white/5 pt-4">
                <div className="flex-1">
                    <label htmlFor="test_to" className={labelClass}>Send a test email to</label>
                    <input id="test_to" type="email" value={test.data.to} onChange={(event) => test.setData('to', event.target.value)} required className={fieldClass} />
                    {test.errors.to && <p className="mt-1 text-xs text-rose-400">{test.errors.to}</p>}
                </div>
                <button type="submit" disabled={test.processing} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white disabled:opacity-60">
                    {test.processing ? 'Sending…' : 'Send test email'}
                </button>
            </form>
        </article>
    );
}

function StripeCard({ settings }: PlatformSettingsProps) {
    const { data, setData, patch, processing, errors } = useForm({
        stripe_publishable_key: settings.stripe_publishable_key ?? '',
        stripe_secret_key: '',
        stripe_webhook_secret: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/platform/stripe');
    };

    return (
        <article className={cardClass}>
            <p className="text-sm font-semibold">Payment gateway (Stripe)</p>
            <p className="mt-1 text-xs text-slate-400">Stripe credentials are encrypted at rest and never redisplayed. Use matching test or live keys. Use Online checkout and refunds from invoice pages once payment keys are saved.</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label htmlFor="stripe_publishable_key" className={labelClass}>Publishable key</label>
                    <input id="stripe_publishable_key" value={data.stripe_publishable_key} onChange={(event) => setData('stripe_publishable_key', event.target.value)} placeholder="pk_live_…" className={fieldClass} />
                    {errors.stripe_publishable_key && <p className="mt-1 text-xs text-rose-400">{errors.stripe_publishable_key}</p>}
                </div>
                <div>
                    <label htmlFor="stripe_secret_key" className={labelClass}>Secret key{settings.stripe_has_secret_key ? ' (leave blank to keep the current one)' : ''}</label>
                    <input id="stripe_secret_key" type="password" value={data.stripe_secret_key} onChange={(event) => setData('stripe_secret_key', event.target.value)} placeholder={settings.stripe_has_secret_key ? '••••••••' : 'sk_live_…'} className={fieldClass} />
                    {errors.stripe_secret_key && <p className="mt-1 text-xs text-rose-400">{errors.stripe_secret_key}</p>}
                </div>
                <div>
                    <label htmlFor="stripe_webhook_secret" className={labelClass}>Webhook signing secret{settings.stripe_has_webhook_secret ? ' (leave blank to keep the current one)' : ''}</label>
                    <input id="stripe_webhook_secret" type="password" value={data.stripe_webhook_secret} onChange={(event) => setData('stripe_webhook_secret', event.target.value)} placeholder={settings.stripe_has_webhook_secret ? '••••••••' : 'whsec_…'} className={fieldClass} />
                    {errors.stripe_webhook_secret && <p className="mt-1 text-xs text-rose-400">{errors.stripe_webhook_secret}</p>}
                </div>
                <button type="submit" disabled={processing} className={buttonClass}>{processing ? 'Saving…' : 'Save payment gateway settings'}</button>
            </form>
            <button type="button" className="mt-4 rounded-lg border border-white/15 px-4 py-2 text-sm text-cyan-300" onClick={() => router.post('/settings/platform/stripe/test')}>Test saved Stripe connection (no charge)</button>
        </article>
    );
}

function StripeSubscriptionsCard({ settings }: PlatformSettingsProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        customer_email: '',
        price_id: '',
        quantity: '' as number | '',
        trial_days: '' as number | '',
    });

    const createSubscription = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/platform/stripe/subscriptions', {
            onSuccess: () => {
                reset('customer_email', 'price_id', 'quantity', 'trial_days');
            },
        });
    };

    return (
        <article className={cardClass}>
            <p className="text-sm font-semibold">Stripe subscriptions</p>
            <p className="mt-1 text-xs text-slate-500">Create subscriptions from a Stripe price ID and monitor active subscriptions.</p>

            <form onSubmit={createSubscription} className="mt-4 space-y-3 rounded-xl border border-white/5 bg-black/20 p-4">
                <div>
                    <label htmlFor="customer_email" className={labelClass}>Customer email</label>
                    <input
                        id="customer_email"
                        type="email"
                        value={data.customer_email}
                        onChange={(event) => setData('customer_email', event.target.value)}
                        required
                        className={fieldClass}
                    />
                    {errors.customer_email && <p className="mt-1 text-xs text-rose-400">{errors.customer_email}</p>}
                </div>
                <div>
                    <label htmlFor="price_id" className={labelClass}>Price ID</label>
                    <input
                        id="price_id"
                        value={data.price_id}
                        onChange={(event) => setData('price_id', event.target.value)}
                        required
                        placeholder="price_..."
                        className={fieldClass}
                    />
                    {errors.price_id && <p className="mt-1 text-xs text-rose-400">{errors.price_id}</p>}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="quantity" className={labelClass}>Quantity</label>
                        <input
                            id="quantity"
                            type="number"
                            value={data.quantity}
                            onChange={(event) => setData('quantity', event.target.value ? Number(event.target.value) : '')}
                            min="1"
                            max="999"
                            className={fieldClass}
                        />
                        {errors.quantity && <p className="mt-1 text-xs text-rose-400">{errors.quantity}</p>}
                    </div>
                    <div>
                        <label htmlFor="trial_days" className={labelClass}>Trial days</label>
                        <input
                            id="trial_days"
                            type="number"
                            value={data.trial_days}
                            onChange={(event) => setData('trial_days', event.target.value ? Number(event.target.value) : '')}
                            min="1"
                            max="365"
                            className={fieldClass}
                        />
                        {errors.trial_days && <p className="mt-1 text-xs text-rose-400">{errors.trial_days}</p>}
                    </div>
                </div>
                <button type="submit" disabled={processing} className={buttonClass}>{processing ? 'Creating…' : 'Create subscription'}</button>
            </form>

            <div className="mt-4">
                {settings.stripe_subscriptions.length === 0 ? (
                    <p className="text-sm text-slate-500">No subscriptions found.</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border border-white/10">
                        <table className="min-w-full text-left text-xs sm:text-sm">
                            <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th className="px-3 py-2">ID</th>
                                    <th className="px-3 py-2">Customer</th>
                                    <th className="px-3 py-2">Price</th>
                                    <th className="px-3 py-2">Quantity</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Current period end</th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {settings.stripe_subscriptions.map((subscription) => (
                                    <tr key={subscription.id}>
                                        <td className="px-3 py-2 font-mono">{subscription.id}</td>
                                        <td className="px-3 py-2">{subscription.customer}</td>
                                        <td className="px-3 py-2">{subscription.price_id}</td>
                                        <td className="px-3 py-2">{subscription.quantity}</td>
                                        <td className="px-3 py-2 capitalize">{subscription.status}</td>
                                        <td className="px-3 py-2">{subscription.current_period_end ? new Date(subscription.current_period_end * 1000).toLocaleString() : '—'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                type="button"
                                                className="rounded border border-rose-400/40 px-2 py-1 text-xs text-rose-300 transition hover:border-rose-300 hover:text-rose-200"
                                                onClick={() => {
                                                    if (confirm(`Cancel subscription ${subscription.id}?`)) {
                                                        router.post(`/settings/platform/stripe/subscriptions/${subscription.id}/cancel`);
                                                    }
                                                }}
                                            >
                                                Cancel
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </article>
    );
}
