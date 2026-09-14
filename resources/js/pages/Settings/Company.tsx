import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../layouts/SettingsLayout';
import type { Company as CompanyType } from '../../types';

interface CompanyPageProps {
    company: CompanyType | null;
    isPlatformAdmin: boolean;
    companies: Pick<CompanyType, 'id' | 'name' | 'code'>[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-2 block text-sm font-medium text-slate-300';

export default function Company({ company, isPlatformAdmin, companies }: CompanyPageProps) {
    return (
        <SettingsLayout title="Company">
            <Head title="Company settings" />

            {isPlatformAdmin && (
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-cyan-400/20 bg-cyan-400/5 p-4">
                    <div>
                        <p className="text-sm font-semibold text-cyan-200">Platform admin</p>
                        <p className="text-xs text-slate-400">
                            {company ? <>Currently managing <strong className="text-slate-200">{company.name}</strong> on its behalf.</> : 'Pick a company below to manage its settings.'}
                        </p>
                    </div>
                    <CompanySwitcher current={company} companies={companies} />
                </div>
            )}

            {company ? (
                <div className="max-w-2xl space-y-6">
                    <CompanyForm company={company} />
                    <TrackingWidgetCard />
                </div>
            ) : (
                <div className="max-w-2xl rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-slate-500">
                    No company selected. Choose one above to view or edit its settings.
                </div>
            )}
        </SettingsLayout>
    );
}

/**
 * The one public tracking link and embed snippet, in one place only staff
 * can reach — not on the public tracking page itself, which any customer
 * (or a competitor) could otherwise lift a ready-made widget from. Search
 * works for every customer's shipment already (tracking numbers are looked
 * up globally, not scoped to this company), so there is nothing here to
 * configure — just the address to hand to whoever runs the company's site.
 */
function TrackingWidgetCard() {
    const [copiedLink, setCopiedLink] = useState(false);
    const [copiedEmbed, setCopiedEmbed] = useState(false);
    const origin = window.location.origin;
    const pageUrl = `${origin}/track`;
    const snippet = `<iframe src="${origin}/track/embed" width="360" height="260" style="border:0;border-radius:12px;" title="Track your shipment"></iframe>`;

    const copy = async (text: string, mark: (value: boolean) => void) => {
        try {
            await navigator.clipboard.writeText(text);
            mark(true);
            setTimeout(() => mark(false), 2000);
        } catch {
            // Clipboard access can be denied by the browser; the fields
            // below are still there to select and copy manually.
        }
    };

    return (
        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Tracking widget</p>
            <p className="mt-1 text-sm text-slate-400">
                One link and one embed code that work for any customer's tracking number — put these on your company's own website, not a customer's. Each shipment also has its own direct tracking link, available on that shipment's page.
            </p>

            <label htmlFor="tracking-page-url" className={`${labelClass} mt-5`}>Public tracking page</label>
            <div className="flex gap-2">
                <input
                    id="tracking-page-url"
                    readOnly
                    value={pageUrl}
                    onFocus={(event) => event.target.select()}
                    className={`${fieldClass} font-mono text-sm`}
                />
                <button onClick={() => copy(pageUrl, setCopiedLink)} className="shrink-0 rounded-xl border border-white/10 px-4 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                    {copiedLink ? 'Copied!' : 'Copy link'}
                </button>
            </div>

            <label htmlFor="tracking-embed-code" className={`${labelClass} mt-5`}>Embed on your website</label>
            <textarea
                id="tracking-embed-code"
                readOnly
                value={snippet}
                onFocus={(event) => event.target.select()}
                rows={2}
                className={`${fieldClass} font-mono text-xs`}
            />
            <button onClick={() => copy(snippet, setCopiedEmbed)} className="mt-2 rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">
                {copiedEmbed ? 'Copied!' : 'Copy embed code'}
            </button>
        </div>
    );
}

function CompanySwitcher({ current, companies }: { current: CompanyType | null; companies: Pick<CompanyType, 'id' | 'name' | 'code'>[] }) {
    const switchTo = (event: React.ChangeEvent<HTMLSelectElement>) => {
        const id = event.target.value;
        if (id) {
            router.post(`/platform/act-as/${id}`);
        }
    };

    const stop = () => router.delete('/platform/act-as');

    return (
        <div className="flex items-center gap-2">
            <select value={current?.id ?? ''} onChange={switchTo} className="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white">
                <option value="" disabled>Select a company…</option>
                {companies.map((option) => (
                    <option key={option.id} value={option.id}>{option.name} ({option.code})</option>
                ))}
            </select>
            {current && (
                <button onClick={stop} className="rounded-lg border border-white/10 px-3 py-2 text-xs text-slate-300 transition hover:border-rose-400/50 hover:text-rose-300">
                    Stop managing
                </button>
            )}
        </div>
    );
}

function CompanyForm({ company }: { company: CompanyType }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: company.name,
        legal_name: company.legal_name ?? '',
        email: company.email ?? '',
        phone: company.phone ?? '',
        timezone: company.timezone,
        default_currency: company.default_currency,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/company');
    };

    return (
        <form onSubmit={submit} className="max-w-2xl space-y-5 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="grid gap-5 sm:grid-cols-2">
                <div>
                    <label htmlFor="name" className={labelClass}>Company name</label>
                    <input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                    {errors.name && <p className="mt-2 text-sm text-rose-400">{errors.name}</p>}
                </div>
                <div>
                    <label htmlFor="legal_name" className={labelClass}>Legal name</label>
                    <input id="legal_name" value={data.legal_name} onChange={(event) => setData('legal_name', event.target.value)} className={fieldClass} />
                    {errors.legal_name && <p className="mt-2 text-sm text-rose-400">{errors.legal_name}</p>}
                </div>
                <div>
                    <label htmlFor="email" className={labelClass}>Email</label>
                    <input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                    {errors.email && <p className="mt-2 text-sm text-rose-400">{errors.email}</p>}
                </div>
                <div>
                    <label htmlFor="phone" className={labelClass}>Phone</label>
                    <input id="phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                    {errors.phone && <p className="mt-2 text-sm text-rose-400">{errors.phone}</p>}
                </div>
                <div>
                    <label htmlFor="timezone" className={labelClass}>Timezone</label>
                    <input id="timezone" value={data.timezone} onChange={(event) => setData('timezone', event.target.value)} required className={fieldClass} />
                    {errors.timezone && <p className="mt-2 text-sm text-rose-400">{errors.timezone}</p>}
                </div>
                <div>
                    <label htmlFor="default_currency" className={labelClass}>Default currency</label>
                    <input id="default_currency" value={data.default_currency} onChange={(event) => setData('default_currency', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} />
                    {errors.default_currency && <p className="mt-2 text-sm text-rose-400">{errors.default_currency}</p>}
                </div>
            </div>
            <button type="submit" disabled={processing} className="rounded-xl bg-cyan-400 px-5 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : 'Save changes'}
            </button>
        </form>
    );
}
