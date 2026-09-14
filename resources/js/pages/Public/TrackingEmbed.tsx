import { Head, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import { statusBadgeClass } from '../../lib/statusColors';
import type { PublicShipment } from '../../types';

interface TrackingEmbedProps {
    trackingNumber: string;
    shipment: PublicShipment | null;
}

/**
 * The compact widget served at /track/embed (blank, reusable by any
 * customer) and /track/{number}/embed (pre-filled to one shipment) for
 * pasting into an iframe on an external site — see EmbedCard on the full
 * tracking page for the snippets these are rendered by. No site nav,
 * nothing that assumes the visitor can navigate elsewhere: this is meant to
 * sit inside a few hundred pixels on somebody else's page. A search inside
 * the iframe just re-navigates that iframe's own document — the parent
 * page embedding it is never touched.
 */
export default function TrackingEmbed({ trackingNumber, shipment }: TrackingEmbedProps) {
    const { data, setData, get, processing } = useForm({ number: trackingNumber });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        get(`/track/${encodeURIComponent(data.number)}/embed`);
    };

    // A tracker left open is usually left open for a while, so it refreshes
    // itself rather than freezing on whatever status it loaded with — the
    // same reason carrier tracking widgets poll. Only worth doing once
    // there's an actual shipment on screen to keep current.
    useEffect(() => {
        if (shipment === null) {
            return;
        }

        const timer = window.setInterval(() => window.location.reload(), 60_000);

        return () => window.clearInterval(timer);
    }, [shipment]);

    return (
        <div className="flex min-h-screen items-center bg-slate-950 p-3 text-slate-100">
            <Head title={trackingNumber ? `Track ${trackingNumber}` : 'Track your shipment'} />

            <div className="w-full rounded-xl border border-white/10 bg-white/[0.035] p-4">
                <form onSubmit={submit} className="flex gap-1.5">
                    <input
                        value={data.number}
                        onChange={(event) => setData('number', event.target.value)}
                        placeholder="Tracking number"
                        className="min-w-0 flex-1 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-xs text-white outline-none placeholder:text-slate-600 focus:border-cyan-400"
                    />
                    <button type="submit" disabled={processing} className="shrink-0 rounded-lg bg-cyan-400 px-2.5 py-1.5 text-xs font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        Track
                    </button>
                </form>

                {trackingNumber === '' && shipment === null && (
                    <p className="mt-3 text-center text-xs text-slate-500">Enter a tracking number above.</p>
                )}

                {trackingNumber !== '' && shipment === null && (
                    <p className="mt-3 text-center text-xs text-slate-400">
                        No shipment found for <span className="font-mono text-white">{trackingNumber}</span>.
                    </p>
                )}

                {shipment !== null && (
                    <div className="mt-3 border-t border-white/5 pt-3">
                        <div className="flex items-center justify-between gap-2">
                            <p className="truncate font-mono text-sm">{shipment.tracking_number}</p>
                            <span className={`shrink-0 rounded-full border px-2 py-0.5 text-[11px] ${statusBadgeClass(shipment.status_color)}`}>{shipment.status_label}</span>
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            {shipment.carrier}
                            {shipment.destination_city && <> · → {shipment.destination_city}, {shipment.destination_country_code}</>}
                        </p>
                        {shipment.last_location && <p className="mt-2 text-xs text-slate-400">Last known location: {shipment.last_location}</p>}

                        {shipment.events[0] && (
                            <div className="mt-3 border-t border-white/5 pt-3">
                                <p className="text-xs font-medium">{shipment.events[0].status_label}</p>
                                <p className="text-[11px] text-slate-600">{new Date(shipment.events[0].occurred_at).toLocaleString()}</p>
                            </div>
                        )}

                        <a href={`/track/${encodeURIComponent(shipment.tracking_number)}`} target="_blank" rel="noreferrer" className="mt-3 block text-[11px] text-cyan-300 hover:text-cyan-200">
                            Full tracking details ↗
                        </a>
                    </div>
                )}
            </div>
        </div>
    );
}
