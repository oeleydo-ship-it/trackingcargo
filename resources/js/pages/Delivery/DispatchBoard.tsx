import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { DispatchAttemptFeedItem, DispatchBoardAssignment, DriverLocationPing, SharedPageProps } from '../../types';

interface DispatchBoardProps {
    activeAssignments: DispatchBoardAssignment[];
    recentAttempts: DispatchAttemptFeedItem[];
    driverLocations: DriverLocationPing[];
}

const statusColor: Record<string, string> = {
    assigned: 'text-slate-400 border-white/10',
    out_for_delivery: 'text-blue-300 border-blue-400/30',
    delivered: 'text-emerald-300 border-emerald-400/30',
    cancelled: 'text-rose-300 border-rose-400/30',
};

export default function DispatchBoard({ activeAssignments, recentAttempts, driverLocations: initialLocations }: DispatchBoardProps) {
    const { auth } = usePage<SharedPageProps>().props;
    const companyId = auth.user?.company?.id;
    const [attempts, setAttempts] = useState<DispatchAttemptFeedItem[]>(recentAttempts);
    const [locations, setLocations] = useState<DriverLocationPing[]>(initialLocations);

    useEffect(() => setAttempts(recentAttempts), [recentAttempts]);
    useEffect(() => setLocations(initialLocations), [initialLocations]);

    useEffect(() => {
        if (!companyId || !window.Echo) {
            return;
        }

        const channelName = `company.${companyId}`;
        const channel = window.Echo.private(channelName);

        channel.listen('.DeliveryAttemptRecorded', (event: DispatchAttemptFeedItem) => {
            setAttempts((current) => (current.some((item) => item.id === event.id) ? current : [event, ...current].slice(0, 50)));
        });

        channel.listen('.DriverLocationUpdated', (event: DriverLocationPing) => {
            setLocations((current) => [event, ...current.filter((item) => item.driver_id !== event.driver_id)]);
        });

        return () => {
            window.Echo?.leave(channelName);
        };
    }, [companyId]);

    return (
        <AppLayout title="Dispatch board">
            <Head title="Dispatch board" />

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <ActiveAssignmentsCard assignments={activeAssignments} />
                    <LiveFeedCard attempts={attempts} />
                </div>
                <DriverLocationsCard locations={locations} />
            </div>
        </AppLayout>
    );
}

function ActiveAssignmentsCard({ assignments }: { assignments: DispatchBoardAssignment[] }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Active deliveries</p>
            <div className="mt-4 space-y-2">
                {assignments.map((assignment) => (
                    <Link
                        key={assignment.id}
                        href={`/shipments/${assignment.shipment_id}/delivery-assignments/${assignment.id}`}
                        className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm hover:border-cyan-400/30"
                    >
                        <div>
                            <p className="font-mono text-xs text-slate-300">{assignment.shipment.tracking_number}</p>
                            <p className="text-xs text-slate-500">{assignment.driver.user.name} · {assignment.shipment.destination_city ?? '—'}</p>
                        </div>
                        <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${statusColor[assignment.status] ?? 'border-white/10 text-slate-400'}`}>{assignment.status.replace(/_/g, ' ')}</span>
                    </Link>
                ))}
                {assignments.length === 0 && <p className="text-sm text-slate-500">No active deliveries.</p>}
            </div>
        </article>
    );
}

function LiveFeedCard({ attempts }: { attempts: DispatchAttemptFeedItem[] }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Live activity</p>
            <div className="mt-4 max-h-96 space-y-2 overflow-y-auto">
                {attempts.map((attempt) => (
                    <div key={attempt.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-xs">
                        <div>
                            <p className="font-mono text-slate-200">{attempt.shipment_tracking_number}</p>
                            <p className="text-slate-500">
                                {attempt.driver_name}
                                {attempt.recipient_name && ` · ${attempt.recipient_name}`}
                                {attempt.failure_reason && ` · ${attempt.failure_reason}`}
                            </p>
                        </div>
                        <div className="text-right">
                            <span className={`rounded-full border px-2 py-0.5 ${attempt.outcome === 'succeeded' ? 'border-emerald-400/30 text-emerald-300' : 'border-rose-400/30 text-rose-300'}`}>{attempt.outcome_label}</span>
                            <p className="mt-1 text-slate-600">{new Date(attempt.attempted_at).toLocaleTimeString()}</p>
                        </div>
                    </div>
                ))}
                {attempts.length === 0 && <p className="text-sm text-slate-500">No delivery attempts yet.</p>}
            </div>
        </article>
    );
}

function DriverLocationsCard({ locations }: { locations: DriverLocationPing[] }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Driver locations</p>
            <div className="mt-4 space-y-2">
                {locations.map((location) => (
                    <div key={location.driver_id} className="rounded-xl border border-white/5 p-3 text-xs">
                        <p className="font-medium text-slate-200">{location.driver_name}</p>
                        <p className="font-mono text-slate-500">{location.latitude.toFixed(5)}, {location.longitude.toFixed(5)}</p>
                        <p className="text-slate-600">{new Date(location.recorded_at).toLocaleTimeString()}</p>
                    </div>
                ))}
                {locations.length === 0 && <p className="text-sm text-slate-500">No driver locations reported yet.</p>}
            </div>
        </article>
    );
}
