import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import SettingsLayout from '../../layouts/SettingsLayout';
import type { PaymentModeOption } from '../../lib/paymentModes';

interface Props {
    modes: PaymentModeOption[];
    canManage: boolean;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-400';

export default function PaymentModes({ modes, canManage }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({ label: '' });

    const add = (event: FormEvent) => {
        event.preventDefault();
        post('/settings/payment-modes', { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <SettingsLayout title="Payment modes">
            <Head title="Payment modes" />
            <p className="mb-6 text-sm text-slate-400">Choose which payment modes staff can assign to shipments. Turning a mode off keeps it visible on older shipments.</p>
            <div className="max-w-2xl space-y-3">
                {modes.map((mode) => <ModeRow key={mode.value} mode={mode} canManage={canManage} />)}
            </div>
            {canManage && (
                <form onSubmit={add} className="mt-6 max-w-2xl rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                    <label htmlFor="new-payment-mode" className="text-sm font-semibold">Add payment mode</label>
                    <div className="mt-3 flex gap-2">
                        <input id="new-payment-mode" value={data.label} onChange={(event) => setData('label', event.target.value)} maxLength={60} required placeholder="e.g. Mobile wallet" className={fieldClass} />
                        <button disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-60">Add</button>
                    </div>
                    {errors.label && <p className="mt-2 text-xs text-rose-400">{errors.label}</p>}
                </form>
            )}
        </SettingsLayout>
    );
}

function ModeRow({ mode, canManage }: { mode: PaymentModeOption; canManage: boolean }) {
    const { data, setData, patch, processing, errors } = useForm({ label: mode.label, active: mode.active });

    const save = (event: FormEvent) => {
        event.preventDefault();
        patch(`/settings/payment-modes/${mode.value}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="rounded-2xl border border-white/10 bg-white/[0.035] p-4">
            <div className="flex flex-wrap items-center gap-3">
                <input aria-label={`Name for ${mode.value}`} value={data.label} onChange={(event) => setData('label', event.target.value)} maxLength={60} required disabled={!canManage} className={`${fieldClass} min-w-48 flex-1`} />
                <label className="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" checked={data.active} onChange={(event) => setData('active', event.target.checked)} disabled={!canManage} /> Available
                </label>
                {canManage && <button disabled={processing} className="rounded-lg border border-cyan-400/40 px-4 py-2 text-sm text-cyan-300 disabled:opacity-60">Save</button>}
            </div>
            <p className="mt-2 text-xs text-slate-500">Code: {mode.value}</p>
            {errors.label && <p className="mt-1 text-xs text-rose-400">{errors.label}</p>}
            {errors.active && <p className="mt-1 text-xs text-rose-400">{errors.active}</p>}
        </form>
    );
}
