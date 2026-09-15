import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Carrier, ShipmentMode } from '../../../types';

interface Props {
    carriers: Carrier[];
    /** Tracking integrations the platform has, from config/carriers.php. */
    integrations: string[];
    canManage: boolean;
}

const MODES: { value: ShipmentMode; label: string }[] = [
    { value: 'air', label: 'Air' },
    { value: 'sea', label: 'Sea' },
    { value: 'road', label: 'Road' },
    { value: 'courier', label: 'Courier' },
];

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function CarriersIndex({ carriers, integrations, canManage }: Props) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);

    const toggleActive = (carrier: Carrier) =>
        router.post(`/settings/carriers/${carrier.id}/active`, { is_active: !carrier.is_active }, { preserveScroll: true });

    return (
        <SettingsLayout title="Carriers">
            <Head title="Carriers" />

            <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-sm text-slate-400">
                    The airlines, shipping lines, couriers and trucking firms you hand cargo to. They appear in the
                    Carrier field when booking or editing a shipment in Operations.
                </p>
                {canManage && (
                    <button type="button" onClick={() => { setAdding((value) => !value); setEditing(null); }} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                        {adding ? 'Cancel' : 'Add carrier'}
                    </button>
                )}
            </div>

            {adding && <CarrierForm integrations={integrations} onDone={() => setAdding(false)} />}

            <div className="space-y-3">
                {carriers.map((carrier) => (
                    <div key={carrier.id} className={`rounded-2xl border border-white/10 bg-white/[0.035] p-4 ${carrier.is_active ? '' : 'opacity-60'}`}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-medium text-white">{carrier.name}</p>
                                    <span className="rounded-md border border-white/10 px-1.5 py-0.5 font-mono text-xs text-slate-400">{carrier.code}</span>
                                    {!carrier.is_active && <Tag>Switched off</Tag>}
                                    {carrier.integration_code && <Tag>Tracked via {carrier.integration_code}</Tag>}
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    {carrier.modes && carrier.modes.length > 0
                                        ? carrier.modes.map((mode) => MODES.find((m) => m.value === mode)?.label ?? mode).join(', ')
                                        : 'All modes'}
                                    {' · '}
                                    {carrier.shipments_count === 1 ? '1 shipment' : `${carrier.shipments_count ?? 0} shipments`}
                                </p>
                                {(carrier.contact_name || carrier.contact_email || carrier.contact_phone || carrier.website) && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        {[carrier.contact_name, carrier.contact_email, carrier.contact_phone].filter(Boolean).join(' · ')}
                                        {carrier.website && (
                                            <>
                                                {(carrier.contact_name || carrier.contact_email || carrier.contact_phone) && ' · '}
                                                <a href={carrier.website} target="_blank" rel="noopener noreferrer" className="text-cyan-300 hover:text-cyan-200">Website</a>
                                            </>
                                        )}
                                    </p>
                                )}
                            </div>

                            {canManage && (
                                <div className="flex shrink-0 gap-3 text-xs">
                                    <button type="button" onClick={() => { setEditing(editing === carrier.id ? null : carrier.id); setAdding(false); }} className="text-cyan-300 hover:text-cyan-200">
                                        {editing === carrier.id ? 'Close' : 'Edit'}
                                    </button>
                                    <button type="button" onClick={() => toggleActive(carrier)} className={carrier.is_active ? 'text-rose-400 hover:text-rose-300' : 'text-emerald-300 hover:text-emerald-200'}>
                                        {carrier.is_active ? 'Switch off' : 'Switch on'}
                                    </button>
                                </div>
                            )}
                        </div>

                        {editing === carrier.id && (
                            <div className="mt-4 border-t border-white/5 pt-4">
                                <CarrierForm carrier={carrier} integrations={integrations} onDone={() => setEditing(null)} />
                            </div>
                        )}
                    </div>
                ))}

                {carriers.length === 0 && !adding && (
                    <div className="rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-slate-500">
                        No carriers yet.{canManage ? ' Add the first one to make it selectable when booking shipments.' : ''}
                    </div>
                )}
            </div>
        </SettingsLayout>
    );
}

function Tag({ children }: { children: React.ReactNode }) {
    return <span className="rounded-full border border-white/10 px-2 py-0.5 text-[10px] uppercase tracking-wider text-slate-500">{children}</span>;
}

function CarrierForm({ carrier, integrations, onDone }: { carrier?: Carrier; integrations: string[]; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors } = useForm({
        name: carrier?.name ?? '',
        code: carrier?.code ?? '',
        modes: (carrier?.modes ?? []) as ShipmentMode[],
        integration_code: carrier?.integration_code ?? '',
        website: carrier?.website ?? '',
        contact_name: carrier?.contact_name ?? '',
        contact_email: carrier?.contact_email ?? '',
        contact_phone: carrier?.contact_phone ?? '',
    });

    const idPrefix = `carrier-${carrier?.id ?? 'new'}`;

    const toggleMode = (mode: ShipmentMode) =>
        setData('modes', data.modes.includes(mode) ? data.modes.filter((m) => m !== mode) : [...data.modes, mode]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (carrier) {
            patch(`/settings/carriers/${carrier.id}`, options);
        } else {
            post('/settings/carriers', options);
        }
    };

    const fieldErrors = errors as Record<string, string | undefined>;
    const modeError = fieldErrors.modes ?? Object.entries(fieldErrors).find(([key]) => key.startsWith('modes.'))?.[1];

    return (
        <form onSubmit={submit} className={carrier ? 'space-y-4' : 'mb-6 space-y-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6'}>
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="sm:col-span-2">
                    <label htmlFor={`${idPrefix}-name`} className={labelClass}>Name</label>
                    <input id={`${idPrefix}-name`} value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Emirates SkyCargo" />
                    {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
                </div>
                <div>
                    <label htmlFor={`${idPrefix}-code`} className={labelClass}>Short code</label>
                    <input id={`${idPrefix}-code`} value={data.code} onChange={(event) => setData('code', event.target.value.toUpperCase())} required maxLength={20} className={`${fieldClass} font-mono`} placeholder="EK" />
                    {errors.code && <p className="mt-1 text-xs text-rose-400">{errors.code}</p>}
                </div>
            </div>

            <div>
                <p className={labelClass}>Modes it handles</p>
                <div className="flex flex-wrap gap-2">
                    {MODES.map((mode) => (
                        <button
                            key={mode.value}
                            type="button"
                            aria-pressed={data.modes.includes(mode.value)}
                            onClick={() => toggleMode(mode.value)}
                            className={`rounded-full border px-3 py-1 text-xs transition ${data.modes.includes(mode.value) ? 'border-cyan-400/40 bg-cyan-400/10 text-cyan-200' : 'border-white/10 text-slate-500 hover:text-slate-300'}`}
                        >
                            {mode.label}
                        </button>
                    ))}
                </div>
                <p className="mt-2 text-xs text-slate-600">Leave all off to offer this carrier for every mode.</p>
                {modeError && <p className="mt-1 text-xs text-rose-400">{modeError}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`${idPrefix}-contact-name`} className={labelClass}>Contact name</label>
                    <input id={`${idPrefix}-contact-name`} value={data.contact_name} onChange={(event) => setData('contact_name', event.target.value)} className={fieldClass} />
                </div>
                <div>
                    <label htmlFor={`${idPrefix}-contact-phone`} className={labelClass}>Contact phone</label>
                    <input id={`${idPrefix}-contact-phone`} value={data.contact_phone} onChange={(event) => setData('contact_phone', event.target.value)} className={fieldClass} />
                    {errors.contact_phone && <p className="mt-1 text-xs text-rose-400">{errors.contact_phone}</p>}
                </div>
                <div>
                    <label htmlFor={`${idPrefix}-contact-email`} className={labelClass}>Contact email</label>
                    <input id={`${idPrefix}-contact-email`} type="email" value={data.contact_email} onChange={(event) => setData('contact_email', event.target.value)} className={fieldClass} />
                    {errors.contact_email && <p className="mt-1 text-xs text-rose-400">{errors.contact_email}</p>}
                </div>
                <div>
                    <label htmlFor={`${idPrefix}-website`} className={labelClass}>Website</label>
                    <input id={`${idPrefix}-website`} type="url" value={data.website} onChange={(event) => setData('website', event.target.value)} className={fieldClass} placeholder="https://" />
                    {errors.website && <p className="mt-1 text-xs text-rose-400">{errors.website}</p>}
                </div>
            </div>

            {integrations.length > 0 && (
                <div className="sm:w-1/2">
                    <label htmlFor={`${idPrefix}-integration`} className={labelClass}>Automatic tracking</label>
                    <select id={`${idPrefix}-integration`} value={data.integration_code} onChange={(event) => setData('integration_code', event.target.value)} className={fieldClass}>
                        <option value="">None — update status manually</option>
                        {integrations.map((code) => (
                            <option key={code} value={code}>{code === 'mock' ? 'Demo tracking feed (mock)' : code}</option>
                        ))}
                    </select>
                    <p className="mt-1 text-xs text-slate-600">Shipments booked with this carrier are polled through this integration.</p>
                    {errors.integration_code && <p className="mt-1 text-xs text-rose-400">{errors.integration_code}</p>}
                </div>
            )}

            <div className="flex gap-3">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : carrier ? 'Save changes' : 'Add carrier'}
                </button>
                <button type="button" onClick={onDone} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-400 hover:text-slate-200">Cancel</button>
            </div>
        </form>
    );
}
