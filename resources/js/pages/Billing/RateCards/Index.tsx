import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../../layouts/AppLayout';
import type { RateCard } from '../../../types';

interface RateCardsIndexProps {
    rateCards: RateCard[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function RateCardsIndex({ rateCards }: RateCardsIndexProps) {
    const [showForm, setShowForm] = useState(false);

    return (
        <AppLayout title="Rate cards">
            <Head title="Rate cards" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{rateCards.length} rate card{rateCards.length === 1 ? '' : 's'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : '+ New rate card'}
                </button>
            </div>

            {showForm && <CreateRateCardForm onCreated={() => setShowForm(false)} />}

            <div className="space-y-4">
                {rateCards.map((rateCard) => (
                    <RateCardCard key={rateCard.id} rateCard={rateCard} />
                ))}
                {rateCards.length === 0 && (
                    <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">No rate cards yet.</p>
                )}
            </div>
        </AppLayout>
    );
}

function CreateRateCardForm({ onCreated }: { onCreated: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        currency: 'AED',
        mode: '' as '' | 'air' | 'sea' | 'road' | 'courier',
        base_fee: '' as number | '',
        min_charge: '' as number | '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/rate-cards', { onSuccess: () => { reset(); onCreated(); } });
    };

    return (
        <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label className={labelClass}>Name</label>
                <input value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Standard Air" />
                {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
            </div>
            <div>
                <label className={labelClass}>Currency</label>
                <input value={data.currency} onChange={(event) => setData('currency', event.target.value.toUpperCase())} maxLength={3} required className={fieldClass} />
                {errors.currency && <p className="mt-1 text-xs text-rose-400">{errors.currency}</p>}
            </div>
            <div>
                <label className={labelClass}>Mode (optional)</label>
                <select value={data.mode} onChange={(event) => setData('mode', event.target.value as typeof data.mode)} className={fieldClass}>
                    <option value="">Any</option>
                    <option value="air">Air</option>
                    <option value="sea">Sea</option>
                    <option value="road">Road</option>
                    <option value="courier">Courier</option>
                </select>
            </div>
            <div>
                <label className={labelClass}>Base fee</label>
                <input type="number" step="0.01" min="0" value={data.base_fee} onChange={(event) => setData('base_fee', event.target.value ? Number(event.target.value) : '')} className={fieldClass} />
            </div>
            <div>
                <label className={labelClass}>Minimum charge</label>
                <input type="number" step="0.01" min="0" value={data.min_charge} onChange={(event) => setData('min_charge', event.target.value ? Number(event.target.value) : '')} className={fieldClass} />
            </div>
            <div className="sm:col-span-2 lg:col-span-4">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Creating…' : 'Create rate card'}
                </button>
            </div>
        </form>
    );
}

function RateCardCard({ rateCard }: { rateCard: RateCard }) {
    const [showTierForm, setShowTierForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        min_weight_kg: '' as number | '',
        max_weight_kg: '' as number | '',
        price_per_kg: '' as number | '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/rate-cards/${rateCard.id}/tiers`, { onSuccess: () => { reset(); setShowTierForm(false); } });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-semibold">{rateCard.name}</p>
                    <p className="text-xs text-slate-500">
                        {rateCard.currency} · {rateCard.mode ?? 'any mode'} · {rateCard.customer ? rateCard.customer.name : 'all customers'} · {rateCard.branch ? rateCard.branch.name : 'company-wide'}
                    </p>
                </div>
                <div className="flex items-center gap-4 text-xs text-slate-400">
                    <span>Base fee: {rateCard.base_fee}</span>
                    <span>Min charge: {rateCard.min_charge}</span>
                    <button onClick={() => setShowTierForm((value) => !value)} className="text-cyan-300 hover:text-cyan-200">{showTierForm ? 'Cancel' : '+ Add tier'}</button>
                </div>
            </div>

            {showTierForm && (
                <form onSubmit={submit} className="mt-4 grid gap-3 rounded-xl border border-white/5 bg-black/20 p-4 sm:grid-cols-4">
                    <div>
                        <label className={labelClass}>Min weight (kg)</label>
                        <input type="number" step="0.001" min="0" value={data.min_weight_kg} onChange={(event) => setData('min_weight_kg', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                        {errors.min_weight_kg && <p className="mt-1 text-xs text-rose-400">{errors.min_weight_kg}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Max weight (kg, optional)</label>
                        <input type="number" step="0.001" min="0" value={data.max_weight_kg} onChange={(event) => setData('max_weight_kg', event.target.value ? Number(event.target.value) : '')} className={fieldClass} />
                        {errors.max_weight_kg && <p className="mt-1 text-xs text-rose-400">{errors.max_weight_kg}</p>}
                    </div>
                    <div>
                        <label className={labelClass}>Price per kg</label>
                        <input type="number" step="0.01" min="0" value={data.price_per_kg} onChange={(event) => setData('price_per_kg', event.target.value ? Number(event.target.value) : '')} required className={fieldClass} />
                        {errors.price_per_kg && <p className="mt-1 text-xs text-rose-400">{errors.price_per_kg}</p>}
                    </div>
                    <div className="flex items-end">
                        <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add tier</button>
                    </div>
                </form>
            )}

            <div className="mt-4 overflow-hidden rounded-xl border border-white/5">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-3 py-2">From (kg)</th>
                            <th className="px-3 py-2">To (kg)</th>
                            <th className="px-3 py-2">Price / kg</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {rateCard.tiers.map((tier) => (
                            <tr key={tier.id}>
                                <td className="px-3 py-2">{tier.min_weight_kg}</td>
                                <td className="px-3 py-2">{tier.max_weight_kg ?? '∞'}</td>
                                <td className="px-3 py-2">{tier.price_per_kg}</td>
                            </tr>
                        ))}
                        {rateCard.tiers.length === 0 && (
                            <tr><td colSpan={3} className="px-3 py-4 text-center text-slate-500">No tiers yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </article>
    );
}
