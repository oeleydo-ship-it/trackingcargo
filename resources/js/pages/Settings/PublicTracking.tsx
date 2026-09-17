import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SettingsLayout from '../../layouts/SettingsLayout';
import type { Company, PublicTrackingPartySettings } from '../../types';

interface PublicTrackingPageProps {
    company: Company | null;
    parties: PublicTrackingPartySettings | null;
    fields: Record<string, string>;
    levels: { value: string; label: string }[];
}

const selectClass = 'w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white outline-none transition focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';

const roles: { key: 'sender' | 'receiver'; label: string; hint: string }[] = [
    { key: 'sender', label: 'Sender', hint: 'The consignor who booked the shipment.' },
    { key: 'receiver', label: 'Receiver', hint: 'The consignee the shipment is going to.' },
];

export default function PublicTracking({ company, parties, fields, levels }: PublicTrackingPageProps) {
    return (
        <SettingsLayout title="Public tracking">
            <Head title="Public tracking settings" />

            {company && parties ? (
                <PartyVisibilityForm parties={parties} fields={fields} levels={levels} />
            ) : (
                <div className="max-w-3xl rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-slate-500">
                    No company selected. Choose one on the Company tab to edit its public tracking page.
                </div>
            )}
        </SettingsLayout>
    );
}

function PartyVisibilityForm({ parties, fields, levels }: { parties: PublicTrackingPartySettings; fields: Record<string, string>; levels: { value: string; label: string }[] }) {
    const { data, setData, patch, processing, errors } = useForm({ parties });

    const setLevel = (role: 'sender' | 'receiver', field: string, value: string) => {
        setData('parties', {
            ...data.parties,
            [role]: { ...data.parties[role], [field]: value },
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch('/settings/public-tracking');
    };

    return (
        <form onSubmit={submit} className="max-w-3xl space-y-5 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div>
                <h3 className="text-sm font-semibold text-slate-200">Sender and receiver details</h3>
                <p className="mt-1 text-sm text-slate-400">
                    Anyone holding a tracking number can open the public tracking page without signing in — these settings decide how much
                    of each party it shows them.
                </p>
                <p className="mt-2 text-xs text-slate-500">
                    <span className="font-medium text-slate-400">Partial</span> shows a name as “John S.”, an address as city and country only,
                    a phone as its last four digits, and an email with the local part obscured. A company or trading name follows the Name setting.
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                {roles.map((role) => (
                    <div key={role.key} className="rounded-xl border border-white/10 p-4">
                        <p className="text-sm font-medium">{role.label}</p>
                        <p className="mt-0.5 text-xs text-slate-500">{role.hint}</p>
                        <div className="mt-4 space-y-3">
                            {Object.entries(fields).map(([field, label]) => (
                                <div key={field}>
                                    <label htmlFor={`${role.key}-${field}`} className="mb-1.5 block text-xs font-medium text-slate-400">{label}</label>
                                    <select
                                        id={`${role.key}-${field}`}
                                        value={data.parties[role.key][field] ?? 'hidden'}
                                        onChange={(event) => setLevel(role.key, field, event.target.value)}
                                        className={selectClass}
                                    >
                                        {levels.map((level) => (
                                            <option key={level.value} value={level.value}>{level.label}</option>
                                        ))}
                                    </select>
                                    {errors[`parties.${role.key}.${field}` as keyof typeof errors] && (
                                        <p className="mt-1 text-xs text-rose-400">{errors[`parties.${role.key}.${field}` as keyof typeof errors]}</p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            <button type="submit" disabled={processing} className="rounded-xl bg-cyan-400 px-5 py-3 font-bold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : 'Save public tracking settings'}
            </button>
        </form>
    );
}
