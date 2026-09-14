import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { CodRemittance } from '../../../types';

interface Outstanding {
    id: number;
    name: string;
    outstanding: number;
}

interface CodRemittancesIndexProps {
    outstanding: Outstanding[];
    recentRemittances: CodRemittance[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

function newIdempotencyKey(): string {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function CodRemittancesIndex({ outstanding, recentRemittances }: CodRemittancesIndexProps) {
    return (
        <AppLayout title="COD reconciliation">
            <Head title="COD reconciliation" />

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <p className="text-sm font-semibold">Outstanding COD by driver</p>
                    <div className="mt-4 space-y-3">
                        {outstanding.map((driver) => (
                            <DriverRow key={driver.id} driver={driver} />
                        ))}
                        {outstanding.length === 0 && <p className="text-sm text-slate-500">No outstanding COD — every driver is reconciled.</p>}
                    </div>
                </article>

                <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <p className="text-sm font-semibold">Recent remittances</p>
                    <div className="mt-4 space-y-2">
                        {recentRemittances.map((remittance) => (
                            <div key={remittance.id} className="rounded-xl border border-white/5 p-3 text-sm">
                                <p className="font-medium">{remittance.driver.user.name} — {remittance.amount} {remittance.currency}</p>
                                <p className="text-xs text-slate-500">{remittance.actor?.name ?? 'System'} · {new Date(remittance.remitted_at).toLocaleString()}</p>
                            </div>
                        ))}
                        {recentRemittances.length === 0 && <p className="text-sm text-slate-500">No remittances recorded yet.</p>}
                    </div>
                </article>
            </div>
        </AppLayout>
    );
}

function DriverRow({ driver }: { driver: Outstanding }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        idempotency_key: newIdempotencyKey(),
        driver_id: driver.id,
        amount: '' as number | '',
        currency: 'AED',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/cod-remittances', {
            onSuccess: () => {
                reset('amount');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <div className="rounded-xl border border-white/5 p-4">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium">{driver.name}</p>
                <p className="text-sm font-semibold text-amber-300">{driver.outstanding.toFixed(2)}</p>
            </div>
            <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-2">
                <div>
                    <label className={labelClass}>Amount</label>
                    <input type="number" step="0.01" min="0.01" value={data.amount} onChange={(event) => setData('amount', event.target.value ? Number(event.target.value) : '')} required className={`${fieldClass} w-32`} />
                </div>
                <div>
                    <label className={labelClass}>Currency</label>
                    <input value={data.currency} onChange={(event) => setData('currency', event.target.value.toUpperCase())} maxLength={3} required className={`${fieldClass} w-20`} />
                </div>
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2.5 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Recording…' : 'Record remittance'}
                </button>
            </form>
            {errors.amount && <p className="mt-1 text-xs text-rose-400">{errors.amount}</p>}
        </div>
    );
}
