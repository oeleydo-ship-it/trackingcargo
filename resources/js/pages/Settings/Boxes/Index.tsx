import { Head, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Box, BoxSize } from '../../../types';

interface BoxesIndexProps {
    boxes: Box[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function BoxesIndex({ boxes }: BoxesIndexProps) {
    const [showForm, setShowForm] = useState(false);

    return (
        <SettingsLayout title="Boxes">
            <Head title="Boxes" />

            <p className="mb-5 max-w-2xl text-sm text-slate-400">
                A box is a kind of packaging (e.g. Standard Box, Poly Mailer). Each box can have several named sizes
                (e.g. Small, Medium, Jumbo), each with its own dimensions — pick a size when booking a shipment or
                adding a package to pre-fill its dimensions. A package can still use a custom size instead.
            </p>

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{boxes.length} box{boxes.length === 1 ? '' : 'es'}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : '+ New box'}
                </button>
            </div>

            {showForm && <BoxForm onDone={() => setShowForm(false)} />}

            <div className="space-y-4">
                {boxes.map((box) => (
                    <BoxCard key={box.id} box={box} />
                ))}
                {boxes.length === 0 && (
                    <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">No boxes yet.</p>
                )}
            </div>
        </SettingsLayout>
    );
}

function BoxForm({ box, onDone }: { box?: Box; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors, reset } = useForm({ name: box?.name ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const onSuccess = () => { reset(); onDone(); };

        if (box) {
            patch(`/settings/boxes/${box.id}`, { onSuccess });
        } else {
            post('/settings/boxes', { onSuccess });
        }
    };

    return (
        <form onSubmit={submit} className="mb-6 flex flex-wrap items-end gap-4 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex-1">
                <label htmlFor={box ? `box-${box.id}-name` : 'new-box-name'} className={labelClass}>Box name</label>
                <input
                    id={box ? `box-${box.id}-name` : 'new-box-name'}
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    className={fieldClass}
                    placeholder="Standard Box"
                />
                {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
            </div>
            <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : box ? 'Save' : 'Create box'}
            </button>
        </form>
    );
}

function BoxCard({ box }: { box: Box }) {
    const [editingBox, setEditingBox] = useState(false);
    const [showSizeForm, setShowSizeForm] = useState(false);
    const [editingSize, setEditingSize] = useState<BoxSize | null>(null);

    const toggleBoxActive = () => {
        router.post(`/settings/boxes/${box.id}/active`, { is_active: !box.is_active }, { preserveScroll: true });
    };

    const toggleSizeActive = (size: BoxSize) => {
        router.post(`/settings/boxes/${box.id}/sizes/${size.id}/active`, { is_active: !size.is_active }, { preserveScroll: true });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-semibold">{box.name}</p>
                    <span className={`mt-1 inline-block rounded-full border px-2 py-0.5 text-xs ${box.is_active ? 'border-emerald-400/30 text-emerald-300' : 'border-white/10 text-slate-500'}`}>
                        {box.is_active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <div className="flex items-center gap-4 text-xs">
                    <button onClick={() => setEditingBox((value) => !value)} className="text-cyan-300 hover:text-cyan-200">{editingBox ? 'Cancel' : 'Edit'}</button>
                    <button onClick={toggleBoxActive} className="text-slate-400 hover:text-white">{box.is_active ? 'Deactivate' : 'Reactivate'}</button>
                    <button onClick={() => { setShowSizeForm((value) => !value); setEditingSize(null); }} className="text-cyan-300 hover:text-cyan-200">
                        {showSizeForm && !editingSize ? 'Cancel' : '+ Add size'}
                    </button>
                </div>
            </div>

            {editingBox && (
                <div className="mt-4">
                    <BoxForm box={box} onDone={() => setEditingBox(false)} />
                </div>
            )}

            {showSizeForm && !editingSize && (
                <div className="mt-4 rounded-xl border border-white/5 bg-black/20 p-4">
                    <SizeForm box={box} onDone={() => setShowSizeForm(false)} />
                </div>
            )}

            <div className="mt-4 overflow-hidden rounded-xl border border-white/5">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-3 py-2">Size</th>
                            <th className="px-3 py-2">Length (cm)</th>
                            <th className="px-3 py-2">Width (cm)</th>
                            <th className="px-3 py-2">Height (cm)</th>
                            <th className="px-3 py-2">Status</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {box.sizes.map((size) => (
                            <>
                                <tr key={size.id}>
                                    <td className="px-3 py-2 font-medium">{size.name}</td>
                                    <td className="px-3 py-2 text-slate-400">{size.length_cm}</td>
                                    <td className="px-3 py-2 text-slate-400">{size.width_cm}</td>
                                    <td className="px-3 py-2 text-slate-400">{size.height_cm}</td>
                                    <td className="px-3 py-2">
                                        <span className={`rounded-full border px-2 py-0.5 text-xs ${size.is_active ? 'border-emerald-400/30 text-emerald-300' : 'border-white/10 text-slate-500'}`}>
                                            {size.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-right text-xs">
                                        <button
                                            onClick={() => { setShowSizeForm(true); setEditingSize(editingSize?.id === size.id ? null : size); }}
                                            className="mr-3 text-cyan-300 hover:text-cyan-200"
                                        >
                                            {editingSize?.id === size.id ? 'Cancel' : 'Edit'}
                                        </button>
                                        <button onClick={() => toggleSizeActive(size)} className="text-slate-400 hover:text-white">
                                            {size.is_active ? 'Deactivate' : 'Reactivate'}
                                        </button>
                                    </td>
                                </tr>
                                {editingSize?.id === size.id && (
                                    <tr key={`${size.id}-edit`}>
                                        <td colSpan={6} className="bg-black/20 px-3 py-4">
                                            <SizeForm box={box} size={size} onDone={() => setEditingSize(null)} />
                                        </td>
                                    </tr>
                                )}
                            </>
                        ))}
                        {box.sizes.length === 0 && (
                            <tr><td colSpan={6} className="px-3 py-4 text-center text-slate-500">No sizes yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </article>
    );
}

function SizeForm({ box, size, onDone }: { box: Box; size?: BoxSize; onDone: () => void }) {
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: size?.name ?? '',
        length_cm: (size?.length_cm ?? '') as number | string,
        width_cm: (size?.width_cm ?? '') as number | string,
        height_cm: (size?.height_cm ?? '') as number | string,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const onSuccess = () => { reset(); onDone(); };

        if (size) {
            patch(`/settings/boxes/${box.id}/sizes/${size.id}`, { onSuccess });
        } else {
            post(`/settings/boxes/${box.id}/sizes`, { onSuccess });
        }
    };

    const prefix = size ? `box-${box.id}-size-${size.id}` : `box-${box.id}-new-size`;

    return (
        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-4">
            <div>
                <label htmlFor={`${prefix}-name`} className={labelClass}>Name</label>
                <input id={`${prefix}-name`} value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} placeholder="Jumbo" />
                {errors.name && <p className="mt-1 text-xs text-rose-400">{errors.name}</p>}
            </div>
            <div>
                <label htmlFor={`${prefix}-length`} className={labelClass}>Length (cm)</label>
                <input id={`${prefix}-length`} type="number" step="0.01" min="0.01" value={data.length_cm} onChange={(event) => setData('length_cm', event.target.value)} required className={fieldClass} />
                {errors.length_cm && <p className="mt-1 text-xs text-rose-400">{errors.length_cm}</p>}
            </div>
            <div>
                <label htmlFor={`${prefix}-width`} className={labelClass}>Width (cm)</label>
                <input id={`${prefix}-width`} type="number" step="0.01" min="0.01" value={data.width_cm} onChange={(event) => setData('width_cm', event.target.value)} required className={fieldClass} />
                {errors.width_cm && <p className="mt-1 text-xs text-rose-400">{errors.width_cm}</p>}
            </div>
            <div>
                <label htmlFor={`${prefix}-height`} className={labelClass}>Height (cm)</label>
                <input id={`${prefix}-height`} type="number" step="0.01" min="0.01" value={data.height_cm} onChange={(event) => setData('height_cm', event.target.value)} required className={fieldClass} />
                {errors.height_cm && <p className="mt-1 text-xs text-rose-400">{errors.height_cm}</p>}
            </div>
            <div className="sm:col-span-4">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : size ? 'Save changes' : 'Add size'}
                </button>
            </div>
        </form>
    );
}
