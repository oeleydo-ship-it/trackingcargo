import { Head, Link } from '@inertiajs/react';
import AppLayout from '../layouts/AppLayout';

interface ShipmentWidget {
    visible: boolean;
    bookedToday?: number;
    inTransit?: number;
    outForDelivery?: number;
    deliveredToday?: number;
    exceptions?: number;
}

interface DeliveriesWidget {
    visible: boolean;
    role?: 'driver' | 'ops';
    active?: number;
    outForDelivery?: number;
    deliveredToday?: number;
}

interface CustomsWidget {
    visible: boolean;
    pending?: number;
    held?: number;
}

interface BillingWidget {
    visible: boolean;
    outstandingBalance?: string;
    openInvoices?: number;
}

interface WebhooksWidget {
    visible: boolean;
    failedLast7Days?: number;
}

interface DashboardProps {
    shipments: ShipmentWidget | null;
    deliveries: DeliveriesWidget | null;
    customs: CustomsWidget | null;
    billing: BillingWidget | null;
    webhooks: WebhooksWidget | null;
}

function StatCard({ label, value, href, color = 'text-cyan-300' }: { label: string; value: number | string; href?: string; color?: string }) {
    const card = (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5 shadow-2xl shadow-black/10 transition hover:border-white/20">
            <p className="text-sm text-slate-400">{label}</p>
            <p className={`mt-4 text-3xl font-semibold ${color}`}>{value}</p>
        </article>
    );

    return href ? <Link href={href}>{card}</Link> : card;
}

export default function Dashboard({ shipments, deliveries, customs, billing, webhooks }: DashboardProps) {
    return (
        <AppLayout title="Operations overview">
            <Head title="Operations overview" />

            {shipments?.visible && (
                <section className="mb-6">
                    <p className="mb-3 text-sm font-semibold text-slate-400">Shipments</p>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <StatCard label="Booked today" value={shipments.bookedToday ?? 0} href="/shipments" />
                        <StatCard label="In transit" value={shipments.inTransit ?? 0} href="/shipments" color="text-blue-300" />
                        <StatCard label="Out for delivery" value={shipments.outForDelivery ?? 0} href="/shipments" color="text-amber-300" />
                        <StatCard label="Delivered today" value={shipments.deliveredToday ?? 0} href="/shipments" color="text-emerald-300" />
                        <StatCard label="Exceptions" value={shipments.exceptions ?? 0} href="/shipments" color={(shipments.exceptions ?? 0) > 0 ? 'text-rose-300' : 'text-slate-300'} />
                    </div>
                </section>
            )}

            <section className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                {deliveries?.visible && deliveries.role === 'driver' && (
                    <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <p className="text-sm font-semibold">My deliveries</p>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Active</dt><dd className="font-semibold text-amber-300">{deliveries.active}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Delivered today</dt><dd className="font-semibold text-emerald-300">{deliveries.deliveredToday}</dd></div>
                        </dl>
                        <Link href="/my-deliveries" className="mt-4 inline-block text-xs text-cyan-300 hover:text-cyan-200">Go to my deliveries →</Link>
                    </article>
                )}

                {deliveries?.visible && deliveries.role === 'ops' && (
                    <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <p className="text-sm font-semibold">Local delivery</p>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Out for delivery</dt><dd className="font-semibold text-amber-300">{deliveries.outForDelivery}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Delivered today</dt><dd className="font-semibold text-emerald-300">{deliveries.deliveredToday}</dd></div>
                        </dl>
                        <Link href="/deliveries/dispatch-board" className="mt-4 inline-block text-xs text-cyan-300 hover:text-cyan-200">Go to dispatch board →</Link>
                    </article>
                )}

                {customs?.visible && (
                    <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <p className="text-sm font-semibold">Customs</p>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Pending / under review</dt><dd className="font-semibold text-amber-300">{customs.pending}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Held</dt><dd className={`font-semibold ${(customs.held ?? 0) > 0 ? 'text-rose-300' : 'text-slate-300'}`}>{customs.held}</dd></div>
                        </dl>
                        <Link href="/customs/queue" className="mt-4 inline-block text-xs text-cyan-300 hover:text-cyan-200">Go to customs queue →</Link>
                    </article>
                )}

                {billing?.visible && (
                    <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <p className="text-sm font-semibold">Billing</p>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Outstanding balance</dt><dd className="font-semibold text-amber-300">{billing.outstandingBalance}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Open invoices</dt><dd className="font-semibold text-slate-200">{billing.openInvoices}</dd></div>
                        </dl>
                    </article>
                )}

                {webhooks?.visible && (
                    <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <p className="text-sm font-semibold">Webhooks</p>
                        <dl className="mt-4 space-y-2 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Failed (7 days)</dt><dd className={`font-semibold ${(webhooks.failedLast7Days ?? 0) > 0 ? 'text-rose-300' : 'text-emerald-300'}`}>{webhooks.failedLast7Days}</dd></div>
                        </dl>
                        <Link href="/settings/webhook-endpoints" className="mt-4 inline-block text-xs text-cyan-300 hover:text-cyan-200">Go to webhooks →</Link>
                    </article>
                )}
            </section>

            {!shipments?.visible && !deliveries?.visible && !customs?.visible && !billing?.visible && !webhooks?.visible && (
                <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">
                    No dashboard widgets are available for your current role.
                </p>
            )}
        </AppLayout>
    );
}
