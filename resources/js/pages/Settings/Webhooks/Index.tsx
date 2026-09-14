import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { SharedPageProps, WebhookEndpoint, WebhookEventType } from '../../../types';

interface WebhooksIndexProps {
    endpoints: WebhookEndpoint[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const availableEventTypes: { value: WebhookEventType; label: string }[] = [
    { value: 'shipment.status_changed', label: 'Shipment status changed' },
    { value: 'invoice.paid', label: 'Invoice paid' },
    { value: '*', label: 'All events (wildcard)' },
];

const statusColor: Record<string, string> = {
    pending: 'text-amber-300 border-amber-400/30',
    succeeded: 'text-emerald-300 border-emerald-400/30',
    failed: 'text-rose-300 border-rose-400/30',
};

export default function WebhooksIndex({ endpoints }: WebhooksIndexProps) {
    const { flash } = usePage<SharedPageProps>().props;
    const [showForm, setShowForm] = useState(false);
    const [retryError, setRetryError] = useState('');
    const [retrying, setRetrying] = useState<number | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm<{
        url: string;
        description: string;
        event_types: WebhookEventType[];
    }>({
        url: '',
        description: '',
        event_types: [],
    });

    const toggleEventType = (value: WebhookEventType) => {
        setData('event_types', data.event_types.includes(value) ? data.event_types.filter((v) => v !== value) : [...data.event_types, value]);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/webhook-endpoints', { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (endpoint: WebhookEndpoint) => {
        if (confirm(`Remove the webhook endpoint for "${endpoint.url}"?`)) {
            router.delete(`/settings/webhook-endpoints/${endpoint.id}`);
        }
    };

    const toggleActive = (endpoint: WebhookEndpoint) => {
        router.patch(`/settings/webhook-endpoints/${endpoint.id}`, {
            url: endpoint.url,
            description: endpoint.description ?? '',
            event_types: endpoint.event_types,
            is_active: !endpoint.is_active,
        });
    };

    return (
        <SettingsLayout title="Webhooks">
            <Head title="Webhooks" />

            {flash.success && flash.success.includes('Secret') && (
                <div className="mb-5 rounded-xl border border-cyan-400/30 bg-cyan-400/10 p-4 text-sm text-cyan-200">
                    {flash.success} — copy it now, it will not be shown again.
                </div>
            )}

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{endpoints.length} endpoint{endpoints.length === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : '+ New endpoint'}
                </button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mb-6 space-y-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <div>
                        <label className={labelClass}>URL (https only)</label>
                        <input value={data.url} onChange={(event) => setData('url', event.target.value)} required placeholder="https://example.com/webhooks/cargoflow" className={fieldClass} />
                        {errors.url && <p className="mt-1 text-xs text-rose-400">{errors.url}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Description (optional)</label>
                        <input value={data.description} onChange={(event) => setData('description', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label className={labelClass}>Event types</label>
                        <div className="space-y-2">
                            {availableEventTypes.map((type) => (
                                <label key={type.value} className="flex items-center gap-2 text-sm text-slate-300">
                                    <input type="checkbox" checked={data.event_types.includes(type.value)} onChange={() => toggleEventType(type.value)} className="size-4 rounded border-white/20 bg-white/5 text-cyan-400" />
                                    {type.label}
                                </label>
                            ))}
                        </div>
                        {errors.event_types && <p className="mt-1 text-xs text-rose-400">{errors.event_types}</p>}
                    </div>
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        {processing ? 'Creating…' : 'Create endpoint'}
                    </button>
                </form>
            )}

            {retryError && <p role="alert" className="mb-4 text-sm text-rose-300">{retryError}</p>}
            <div className="space-y-4">
                {endpoints.map((endpoint) => (
                    <article key={endpoint.id} className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p className="break-all font-mono text-sm">{endpoint.url}</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    {endpoint.description ?? 'No description'} · {endpoint.event_types.join(', ')}
                                </p>
                            </div>
                            <div className="flex items-center gap-3">
                                <span className={`rounded-full border px-2 py-0.5 text-xs ${endpoint.is_active ? 'border-emerald-400/30 text-emerald-300' : 'border-white/10 text-slate-500'}`}>
                                    {endpoint.is_active ? 'Active' : 'Disabled'}
                                </span>
                                <button onClick={() => toggleActive(endpoint)} className="text-xs text-cyan-300 hover:text-cyan-200">
                                    {endpoint.is_active ? 'Disable' : 'Enable'}
                                </button>
                                <button onClick={() => remove(endpoint)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                            </div>
                        </div>

                        <div className="mt-4 overflow-hidden rounded-xl border border-white/5">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2">Event</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">Attempts</th>
                                        <th className="px-3 py-2">Response</th>
                                        <th className="px-3 py-2">Last attempt</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-white/5">
                                    {endpoint.deliveries.map((delivery) => (
                                        <tr key={delivery.id}>
                                            <td className="px-3 py-2 font-mono text-xs">{delivery.event_type}</td>
                                            <td className="px-3 py-2">
                                                <span className={`rounded-full border px-2 py-0.5 text-xs ${statusColor[delivery.status] ?? 'border-white/10 text-slate-400'}`}>{delivery.status}</span>
                                                {delivery.status === 'failed' && endpoint.is_active && <button disabled={retrying !== null} className="ml-3 text-xs text-cyan-300 disabled:opacity-50" onClick={() => { setRetryError(''); setRetrying(delivery.id); router.post(`/settings/webhook-deliveries/${delivery.id}/retry`, {}, { preserveScroll: true, onError: (errors) => setRetryError(errors.delivery ?? 'Unable to retry this delivery.'), onFinish: () => setRetrying(null) }); }}>Retry</button>}
                                            </td>
                                            <td className="px-3 py-2">{delivery.attempts}</td>
                                            <td className="px-3 py-2">{delivery.response_status ?? '—'}</td>
                                            <td className="px-3 py-2 text-xs text-slate-500">{delivery.last_attempted_at ? new Date(delivery.last_attempted_at).toLocaleString() : '—'}</td>
                                        </tr>
                                    ))}
                                    {endpoint.deliveries.length === 0 && (
                                        <tr><td colSpan={5} className="px-3 py-4 text-center text-slate-500">No deliveries yet.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </article>
                ))}
                {endpoints.length === 0 && (
                    <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">No webhook endpoints yet.</p>
                )}
            </div>
        </SettingsLayout>
    );
}
