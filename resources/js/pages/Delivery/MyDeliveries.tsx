import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { DeliveryAssignmentSummary } from '../../types';

interface MyDeliveriesProps {
    assignments: (DeliveryAssignmentSummary & {
        shipment: { id: number; tracking_number: string; destination_city: string | null; destination_country_code: string | null };
        vehicle: { registration_number: string } | null;
    })[];
}

const statusColor: Record<string, string> = {
    assigned: 'text-slate-400 border-white/10',
    out_for_delivery: 'text-blue-300 border-blue-400/30',
    delivered: 'text-emerald-300 border-emerald-400/30',
    cancelled: 'text-rose-300 border-rose-400/30',
};

export default function MyDeliveries({ assignments }: MyDeliveriesProps) {
    const [sharing, setSharing] = useState(false);
    const [lastPing, setLastPing] = useState<string | null>(null);
    const watchId = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            if (watchId.current !== null) {
                navigator.geolocation.clearWatch(watchId.current);
            }
        };
    }, []);

    const pingLocation = (latitude: number, longitude: number) => {
        window.axios
            .post('/driver-locations', { latitude, longitude })
            .then(() => setLastPing(new Date().toLocaleTimeString()))
            .catch(() => undefined);
    };

    const toggleSharing = () => {
        if (sharing) {
            if (watchId.current !== null) {
                navigator.geolocation.clearWatch(watchId.current);
                watchId.current = null;
            }
            setSharing(false);
            return;
        }

        if (!navigator.geolocation) {
            return;
        }

        watchId.current = navigator.geolocation.watchPosition(
            (position) => pingLocation(position.coords.latitude, position.coords.longitude),
            () => undefined,
            { enableHighAccuracy: true, maximumAge: 15000 },
        );
        setSharing(true);
    };

    return (
        <AppLayout title="My deliveries">
            <Head title="My deliveries" />

            <div className="mb-6 flex items-center justify-between">
                <p className="text-sm text-slate-400">{assignments.length} active deliver{assignments.length === 1 ? 'y' : 'ies'}</p>
                <button
                    onClick={toggleSharing}
                    className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${sharing ? 'bg-emerald-400 text-slate-950 hover:bg-emerald-300' : 'border border-white/10 text-slate-300 hover:border-cyan-400/50'}`}
                >
                    {sharing ? 'Sharing location' : 'Share my location'}
                </button>
            </div>
            {sharing && lastPing && <p className="mb-4 text-xs text-slate-500">Last ping: {lastPing}</p>}

            <div className="space-y-3">
                {assignments.map((assignment) => (
                    <Link
                        key={assignment.id}
                        href={`/shipments/${assignment.shipment.id}/delivery-assignments/${assignment.id}`}
                        className="block rounded-2xl border border-white/10 bg-white/[0.035] p-5 hover:border-cyan-400/30"
                    >
                        <div className="flex items-center justify-between">
                            <p className="font-mono text-sm font-semibold">{assignment.shipment.tracking_number}</p>
                            <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[assignment.status] ?? 'border-white/10 text-slate-400'}`}>{assignment.status.replace(/_/g, ' ')}</span>
                        </div>
                        <p className="mt-1 text-sm text-slate-400">
                            {[assignment.shipment.destination_city, assignment.shipment.destination_country_code].filter(Boolean).join(', ') || 'No destination on file'}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {assignment.vehicle ? `Vehicle ${assignment.vehicle.registration_number}` : 'No vehicle assigned'}
                            {assignment.scheduled_date && ` · Scheduled ${assignment.scheduled_date}`}
                        </p>
                    </Link>
                ))}
                {assignments.length === 0 && (
                    <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">No deliveries assigned right now.</p>
                )}
            </div>
        </AppLayout>
    );
}
