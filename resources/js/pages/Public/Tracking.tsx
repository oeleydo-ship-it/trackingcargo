import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Brand from '../../components/Brand';
import { statusBadgeClass } from '../../lib/statusColors';
import { useLightTheme } from '../../lib/theme';
import type { PublicShipment, PublicTrackingParty } from '../../types';
import { formatEventDate } from '../../lib/eventDate';

interface TrackingProps {
    trackingNumber: string;
    shipment: PublicShipment | null;
}

export default function Tracking({ trackingNumber, shipment }: TrackingProps) {
    const { data, setData, get, processing } = useForm({ number: trackingNumber });

    useLightTheme();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        get(`/track/${encodeURIComponent(data.number)}`);
    };

    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <Head title={trackingNumber ? `Track ${trackingNumber}` : 'Track your shipment'} />
            <div className="mx-auto max-w-2xl px-6 py-16">
                <Link href="/"><Brand /></Link>

                <h1 className="mt-10 text-2xl font-semibold">Track your shipment</h1>
                <form onSubmit={submit} className="mt-6 flex gap-2">
                    <input
                        value={data.number}
                        onChange={(event) => setData('number', event.target.value)}
                        placeholder="Enter tracking number"
                        className="flex-1 rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10"
                    />
                    <button type="submit" disabled={processing} className="rounded-xl bg-cyan-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">Track</button>
                </form>

                {trackingNumber !== '' && shipment === null && (
                    <div className="mt-10 rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-slate-400">
                        No shipment found for <span className="font-mono text-white">{trackingNumber}</span>.
                    </div>
                )}

                {shipment !== null && (
                    <div className="mt-10 space-y-6">
                        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                            <p className="text-xs text-slate-500">{shipment.carrier}</p>
                            <p className="mt-1 font-mono text-lg">{shipment.tracking_number}</p>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <span className={`rounded-full border px-3 py-1 text-xs ${statusBadgeClass(shipment.status_color)}`}>{shipment.status_label}</span>
                                <span className="text-xs text-slate-500 uppercase">{shipment.mode}</span>
                                {shipment.destination_city && <span className="text-xs text-slate-500">→ {shipment.destination_city}, {shipment.destination_country_code}</span>}
                            </div>
                            {shipment.last_location && <p className="mt-3 text-sm text-slate-400">Last known location: {shipment.last_location}</p>}
                        </div>

                        {(shipment.sender || shipment.receiver) && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <PartyCard label="From" party={shipment.sender} />
                                <PartyCard label="To" party={shipment.receiver} />
                            </div>
                        )}

                        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                            <p className="text-sm font-semibold">Tracking history</p>
                            <div className="mt-4 space-y-4">
                                {shipment.events.map((event, index) => (
                                    <div key={index} className="border-l-2 border-cyan-400/30 pl-4">
                                        <p className="text-sm font-medium">{event.status_label}</p>
                                        {event.location && <p className="text-xs text-slate-400">{event.location}</p>}
                                        {event.description && <p className="text-xs text-slate-500">{event.description}</p>}
                                        <p className="mt-1 text-xs text-slate-600">{formatEventDate(event.occurred_at)}</p>
                                    </div>
                                ))}
                                {shipment.events.length === 0 && <p className="text-sm text-slate-500">No tracking updates yet.</p>}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </main>
    );
}

function PartyCard({ label, party }: { label: string; party: PublicTrackingParty | null }) {
    if (party === null) {
        return null;
    }

    // Any field the company has hidden arrives as null, so the card renders
    // whatever is left without leaving gaps where the rest would have been.
    const locality = [party.city, party.state, party.postal_code].filter(Boolean).join(', ');

    return (
        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-xs uppercase tracking-wider text-slate-500">{label}</p>
            {party.name && <p className="mt-1 font-medium">{party.name}</p>}
            {party.company_name && <p className="text-sm text-slate-300">{party.company_name}</p>}

            {(party.address_lines.length > 0 || locality || party.country_code) && (
                <address className="mt-2 text-sm not-italic text-slate-400">
                    {party.address_lines.map((line) => <span key={line} className="block">{line}</span>)}
                    {locality && <span className="block">{locality}</span>}
                    {party.country_code && <span className="block">{party.country_code}</span>}
                </address>
            )}

            {(party.phone || party.email) && (
                <div className="mt-2 text-sm text-slate-400">
                    {party.phone && <p>{party.phone}</p>}
                    {party.email && <p className="break-all">{party.email}</p>}
                </div>
            )}
        </div>
    );
}
