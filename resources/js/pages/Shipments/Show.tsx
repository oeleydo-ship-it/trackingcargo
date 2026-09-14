import { Head, Link, router, useForm } from '@inertiajs/react';
import CountrySelect from '../../components/CountrySelect';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { statusBadgeClass, statusDotClass } from '../../lib/statusColors';
import type { Box, Shipment, ShipmentParty, TrackingSettings } from '../../types';

interface ShowProps {
    shipment: Shipment;
    allowedTransitions: { value: string; label: string; color: string }[];
    statuses: Record<string, { name: string; color: string }>;
    trackingUrl: string;
    boxes: Box[];
    trackingSettings: TrackingSettings;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export default function Show({ shipment, allowedTransitions, statuses, trackingUrl, boxes, trackingSettings }: ShowProps) {
    return (
        <AppLayout title={shipment.tracking_number}>
            <Head title={shipment.tracking_number} />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-slate-500">{shipment.mode.toUpperCase()} · {shipment.currency}</p>
                    <TrackingNumberHeading shipment={shipment} trackingSettings={trackingSettings} />
                </div>
                <div className="flex items-center gap-3">
                    <span className={`rounded-full border px-3 py-1 text-xs ${statusBadgeClass(shipment.shipment_status?.color)}`}>{shipment.shipment_status?.name ?? shipment.status.replace(/_/g, ' ')}</span>
                    <DeleteShipmentButton shipment={shipment} />
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                <div className="space-y-6">
                    <SummaryCard shipment={shipment} />
                    <PartiesCard shipment={shipment} />
                    <PackagesCard shipment={shipment} boxes={boxes} />
                    <RouteLegsCard shipment={shipment} />
                    <CustomsCard shipment={shipment} />
                    <DeliveryCard shipment={shipment} />
                    <TimelineCard shipment={shipment} statuses={statuses} />
                </div>
                <div className="space-y-6">
                    <TransitionCard shipment={shipment} allowedTransitions={allowedTransitions} />
                    <BarcodeCard shipment={shipment} trackingUrl={trackingUrl} />
                </div>
            </div>
        </AppLayout>
    );
}

function DeleteShipmentButton({ shipment }: { shipment: Shipment }) {
    const [confirming, setConfirming] = useState(false);

    if (confirming) {
        return (
            <div className="flex items-center gap-2 text-xs">
                <span className="text-slate-400">Delete this shipment?</span>
                <button onClick={() => router.delete(`/shipments/${shipment.id}`)} className="rounded-lg bg-rose-500 px-3 py-1.5 font-semibold text-white transition hover:bg-rose-400">
                    Delete
                </button>
                <button onClick={() => setConfirming(false)} className="rounded-lg border border-white/10 px-3 py-1.5 text-slate-400 transition hover:text-slate-200">
                    Cancel
                </button>
            </div>
        );
    }

    return (
        <button onClick={() => setConfirming(true)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-rose-400 transition hover:border-rose-400/50 hover:text-rose-300">
            Delete
        </button>
    );
}

/**
 * The tracking number is only renameable while the shipment is still a draft
 * and the company opted into manual numbers — once it is booked the number is
 * on printed labels and has been quoted to the customer.
 */
function TrackingNumberHeading({ shipment, trackingSettings }: { shipment: Shipment; trackingSettings: TrackingSettings }) {
    const [editing, setEditing] = useState(false);
    const canRename = trackingSettings.allowManual && (shipment.shipment_status?.role === 'draft' || shipment.shipment_status?.is_initial === true);

    // The update endpoint validates the whole shipment, so the unchanged
    // fields ride along with the renamed tracking number.
    const { data, setData, patch, processing, errors, reset } = useForm({
        tracking_number: shipment.tracking_number,
        branch_id: shipment.branch_id,
        customer_id: shipment.customer_id,
        mode: shipment.mode,
        carrier_code: shipment.carrier_code,
        origin_country_code: shipment.origin_country_code,
        destination_country_code: shipment.destination_country_code,
        destination_city: shipment.destination_city,
        declared_value: shipment.declared_value,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/shipments/${shipment.id}`, {
            onSuccess: () => setEditing(false),
        });
    };

    if (!editing) {
        return (
            <div className="flex items-center gap-3">
                <h2 className="text-xl font-semibold">{shipment.tracking_number}</h2>
                {canRename && (
                    <button onClick={() => setEditing(true)} className="text-xs text-cyan-300 hover:text-cyan-200">Rename</button>
                )}
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            <div>
                <input
                    value={data.tracking_number}
                    onChange={(event) => setData('tracking_number', event.target.value.toUpperCase())}
                    maxLength={40}
                    required
                    className={`${fieldClass} font-mono`}
                />
                {errors.tracking_number && <p className="mt-1 text-xs text-rose-400">{errors.tracking_number}</p>}
            </div>
            <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2.5 text-xs font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                {processing ? 'Saving…' : 'Save'}
            </button>
            <button type="button" onClick={() => { reset(); setEditing(false); }} className="rounded-lg border border-white/10 px-3 py-2.5 text-xs text-slate-400 transition hover:text-slate-200">
                Cancel
            </button>
        </form>
    );
}

function SummaryCard({ shipment }: { shipment: Shipment }) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return <SummaryEditForm shipment={shipment} onDone={() => setEditing(false)} />;
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Summary</p>
                <button onClick={() => setEditing(true)} className="text-xs text-cyan-300 hover:text-cyan-200">Edit</button>
            </div>
            <dl className="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div><dt className="text-xs text-slate-500">Destination</dt><dd>{[shipment.destination_city, shipment.destination_country_code].filter(Boolean).join(', ') || '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Branch</dt><dd>{shipment.branch?.name ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Customer</dt><dd>{shipment.customer?.name ?? '—'}</dd></div>
                <div>
                    <dt className="text-xs text-slate-500">Batch</dt>
                    <dd>{shipment.batch
                        ? <Link href={`/batches/${shipment.batch.id}`} className="text-cyan-300 hover:text-cyan-200">{shipment.batch.batch_number}</Link>
                        : '—'}</dd>
                </div>
                <div><dt className="text-xs text-slate-500">Packages</dt><dd>{shipment.package_count}</dd></div>
                <div><dt className="text-xs text-slate-500">Actual weight</dt><dd>{shipment.declared_weight_kg} kg</dd></div>
                <div><dt className="text-xs text-slate-500">Volumetric weight</dt><dd>{shipment.volumetric_weight_kg} kg</dd></div>
                <div><dt className="text-xs text-slate-500">Chargeable weight</dt><dd className="font-semibold text-cyan-300">{shipment.chargeable_weight_kg} kg</dd></div>
                <div><dt className="text-xs text-slate-500">Declared value</dt><dd>{shipment.declared_value ? `${shipment.currency} ${shipment.declared_value}` : '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Last location</dt><dd>{shipment.last_location ?? '—'}</dd></div>
                <div><dt className="text-xs text-slate-500">Carrier</dt><dd className="capitalize">{shipment.carrier_code ?? 'None'}</dd></div>
            </dl>
        </article>
    );
}

/**
 * Corrects the shipment's own details — see ShipmentService::update() for
 * why this is no longer limited to draft shipments. Branch and customer
 * aren't offered here: branch is baked into the tracking number's prefix
 * and every batch it can join (reassigning it is a different, unbuilt
 * operation), and the customer link is a separate lookup this form doesn't
 * carry — both simply ride along unchanged.
 */
function SummaryEditForm({ shipment, onDone }: { shipment: Shipment; onDone: () => void }) {
    const { data, setData, patch, processing, errors } = useForm({
        branch_id: shipment.branch_id,
        mode: shipment.mode,
        carrier_code: shipment.carrier_code ?? '',
        destination_country_code: shipment.destination_country_code ?? '',
        destination_city: shipment.destination_city ?? '',
        declared_value: shipment.declared_value ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/shipments/${shipment.id}`, { onSuccess: onDone });
    };

    return (
        <article className="rounded-2xl border border-cyan-400/30 bg-cyan-400/5 p-6">
            <p className="text-sm font-semibold">Edit summary</p>
            <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label className={labelClass}>Mode</label>
                    <select value={data.mode} onChange={(event) => setData('mode', event.target.value as typeof data.mode)} className={fieldClass}>
                        <option value="air">Air</option>
                        <option value="sea">Sea</option>
                        <option value="road">Road</option>
                        <option value="courier">Courier</option>
                    </select>
                    {errors.mode && <p className="mt-1 text-xs text-rose-400">{errors.mode}</p>}
                </div>
                <div>
                    <label className={labelClass}>Carrier</label>
                    <select value={data.carrier_code} onChange={(event) => setData('carrier_code', event.target.value)} className={fieldClass}>
                        <option value="">None</option>
                        <option value="mock">Mock carrier (demo tracking feed)</option>
                    </select>
                    {errors.carrier_code && <p className="mt-1 text-xs text-rose-400">{errors.carrier_code}</p>}
                </div>
                <div>
                    <label className={labelClass}>Declared value</label>
                    <input type="number" step="0.01" min="0" value={data.declared_value ?? ''} onChange={(event) => setData('declared_value', event.target.value)} className={fieldClass} />
                    {errors.declared_value && <p className="mt-1 text-xs text-rose-400">{errors.declared_value}</p>}
                </div>
                <div>
                    <label className={labelClass}>Destination city</label>
                    <input value={data.destination_city} onChange={(event) => setData('destination_city', event.target.value)} className={fieldClass} />
                    {errors.destination_city && <p className="mt-1 text-xs text-rose-400">{errors.destination_city}</p>}
                </div>
                <div>
                    <label className={labelClass}>Destination country</label>
                    <CountrySelect aria-label="Destination country" value={data.destination_country_code} onChange={(event) => setData('destination_country_code', event.target.value)} required className={fieldClass} />
                    {errors.destination_country_code && <p className="mt-1 text-xs text-rose-400">{errors.destination_country_code}</p>}
                </div>
                <div className="col-span-2 flex items-end gap-2 sm:col-span-3">
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        {processing ? 'Saving…' : 'Save'}
                    </button>
                    <button type="button" onClick={onDone} className="rounded-lg border border-white/10 px-4 py-2.5 text-sm text-slate-400 transition hover:text-slate-200">Cancel</button>
                </div>
            </form>
        </article>
    );
}

const partyRoleLabel: Record<string, string> = {
    consignor: 'Consignor · sender',
    consignee: 'Consignee · receiver',
    notify: 'Notify party',
};

function PartiesCard({ shipment }: { shipment: Shipment }) {
    const [editingId, setEditingId] = useState<number | null>(null);

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Parties</p>
            <p className="mt-1 text-xs text-slate-600">
                The carrier moving this shipment is {shipment.carrier_code ?? 'not set'} — see Carrier under details.
            </p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {shipment.parties.map((party) => (
                    editingId === party.id
                        ? <PartyEditForm key={party.id} shipment={shipment} party={party} onDone={() => setEditingId(null)} />
                        : <PartyView key={party.id} party={party} onEdit={() => setEditingId(party.id)} />
                ))}
            </div>
        </article>
    );
}

function PartyView({ party, onEdit }: { party: ShipmentParty; onEdit: () => void }) {
    const address = party.addresses?.[0] ?? null;

    return (
        <div className="rounded-xl border border-white/5 p-3 text-sm">
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wider text-cyan-300">
                    {partyRoleLabel[party.role] ?? party.role}
                </p>
                <button onClick={onEdit} className="shrink-0 text-xs text-cyan-300 hover:text-cyan-200">Edit</button>
            </div>
            <p className="mt-1 font-medium">{party.name}</p>
            {party.company_name && <p className="text-xs text-slate-500">{party.company_name}</p>}
            {party.customer && (
                <Link href={`/crm/customers/${party.customer.id}`} className="mt-1 inline-block text-xs text-cyan-300 hover:text-cyan-200">
                    {party.customer.customer_number} · on file
                </Link>
            )}
            <p className="text-xs text-slate-500">{[party.email, party.phone].filter(Boolean).join(' · ')}</p>

            {address ? (
                <address className="mt-2 border-t border-white/5 pt-2 text-xs not-italic text-slate-400">
                    <span className="block">{address.line1}</span>
                    {address.line2 && <span className="block">{address.line2}</span>}
                    <span className="block">
                        {[address.city, address.state, address.postal_code].filter(Boolean).join(', ')}
                    </span>
                    <span className="block">{address.country_code}</span>
                    {(address.contact_name || address.contact_phone) && (
                        <span className="mt-1 block text-slate-500">
                            Contact: {[address.contact_name, address.contact_phone].filter(Boolean).join(' · ')}
                        </span>
                    )}
                </address>
            ) : (
                <p className="mt-2 border-t border-white/5 pt-2 text-xs text-slate-600">No address recorded.</p>
            )}
        </div>
    );
}

/**
 * Corrects a party's contact details and address after booking — see
 * ShipmentService::updateParty() for why this is allowed regardless of
 * shipment status. customer_id isn't editable here: these fields are
 * already a booking-time snapshot independent of any linked customer.
 */
function PartyEditForm({ shipment, party, onDone }: { shipment: Shipment; party: ShipmentParty; onDone: () => void }) {
    const address = party.addresses?.[0] ?? null;
    const { data, setData, patch, processing, errors } = useForm({
        name: party.name,
        company_name: party.company_name ?? '',
        email: party.email ?? '',
        phone: party.phone ?? '',
        tax_id: party.tax_id ?? '',
        address: {
            line1: address?.line1 ?? '',
            line2: address?.line2 ?? '',
            city: address?.city ?? '',
            state: address?.state ?? '',
            postal_code: address?.postal_code ?? '',
            country_code: address?.country_code ?? '',
            contact_name: address?.contact_name ?? '',
            contact_phone: address?.contact_phone ?? '',
        },
    });

    const setAddress = (field: keyof typeof data.address, value: string) => {
        setData('address', { ...data.address, [field]: value });
    };

    // Inertia's error bag is keyed by the dotted field path the backend
    // validated against (e.g. "address.line1"), which useForm's own type
    // doesn't know about since `address` is a nested object here.
    const addressError = (field: string): string | undefined => (errors as Record<string, string>)[`address.${field}`];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/shipments/${shipment.id}/parties/${party.id}`, { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="rounded-xl border border-cyan-400/30 bg-cyan-400/5 p-3 text-sm">
            <p className="text-xs font-semibold uppercase tracking-wider text-cyan-300">
                {partyRoleLabel[party.role] ?? party.role}
            </p>
            <div className="mt-2 space-y-2">
                <input placeholder="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} required className={fieldClass} />
                {errors.name && <p className="text-xs text-rose-400">{errors.name}</p>}
                <input placeholder="Company (optional)" value={data.company_name} onChange={(event) => setData('company_name', event.target.value)} className={fieldClass} />
                <div className="grid grid-cols-2 gap-2">
                    <input placeholder="Email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} className={fieldClass} />
                    <input placeholder="Phone" value={data.phone} onChange={(event) => setData('phone', event.target.value)} className={fieldClass} />
                </div>
                {(errors.email || errors.phone) && <p className="text-xs text-rose-400">{errors.email ?? errors.phone}</p>}

                <input placeholder="Address line 1" value={data.address.line1} onChange={(event) => setAddress('line1', event.target.value)} className={fieldClass} />
                {addressError('line1') && <p className="text-xs text-rose-400">{addressError('line1')}</p>}
                <input placeholder="Address line 2 (optional)" value={data.address.line2} onChange={(event) => setAddress('line2', event.target.value)} className={fieldClass} />
                <div className="grid grid-cols-3 gap-2">
                    <input placeholder="City" value={data.address.city} onChange={(event) => setAddress('city', event.target.value)} className={fieldClass} />
                    <input placeholder="State" value={data.address.state} onChange={(event) => setAddress('state', event.target.value)} className={fieldClass} />
                    <input placeholder="Postal code" value={data.address.postal_code} onChange={(event) => setAddress('postal_code', event.target.value)} className={fieldClass} />
                </div>
                {addressError('city') && <p className="text-xs text-rose-400">{addressError('city')}</p>}
                <div className="grid grid-cols-2 gap-2">
                    <CountrySelect value={data.address.country_code} onChange={(event) => setAddress('country_code', event.target.value)} className={fieldClass} />
                    <input placeholder="Contact phone" value={data.address.contact_phone} onChange={(event) => setAddress('contact_phone', event.target.value)} className={fieldClass} />
                </div>
                {addressError('country_code') && <p className="text-xs text-rose-400">{addressError('country_code')}</p>}
            </div>
            <div className="mt-3 flex gap-2">
                <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Saving…' : 'Save'}
                </button>
                <button type="button" onClick={onDone} className="rounded-lg border border-white/10 px-3 py-2 text-xs text-slate-400 transition hover:text-slate-200">Cancel</button>
            </div>
        </form>
    );
}

function PackagesCard({ shipment, boxes }: { shipment: Shipment; boxes: Box[] }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({
        weight_kg: '', box_id: '' as number | '', box_size_id: '' as number | '', length: '', width: '', height: '', description: '',
    });

    const selectedBox = boxes.find((box) => box.id === data.box_id);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipment.id}/packages`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (packageId: number) => {
        if (confirm('Remove this package?')) {
            router.delete(`/shipments/${shipment.id}/packages/${packageId}`);
        }
    };

    const selectBox = (value: string) => {
        setData((current) => ({ ...current, box_id: value ? Number(value) : '', box_size_id: '' }));
    };

    const selectBoxSize = (value: string) => {
        const boxSizeId = value ? Number(value) : '';
        const size = selectedBox?.sizes.find((s) => s.id === boxSizeId);
        setData((current) => ({
            ...current,
            box_size_id: boxSizeId,
            length: size ? size.length_cm : current.length,
            width: size ? size.width_cm : current.width,
            height: size ? size.height_cm : current.height,
        }));
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Packages</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <label htmlFor="new-pkg-box" className={labelClass}>Box</label>
                        <select id="new-pkg-box" value={data.box_id} onChange={(event) => selectBox(event.target.value)} className={fieldClass}>
                            <option value="">Custom (no box)</option>
                            {boxes.map((box) => (
                                <option key={box.id} value={box.id}>{box.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="new-pkg-size" className={labelClass}>Size</label>
                        <select
                            id="new-pkg-size"
                            value={data.box_size_id}
                            disabled={!selectedBox}
                            onChange={(event) => selectBoxSize(event.target.value)}
                            className={`${fieldClass} disabled:cursor-not-allowed disabled:opacity-40`}
                        >
                            <option value="">{selectedBox ? 'Select a size…' : 'Choose a box first'}</option>
                            {selectedBox?.sizes.map((size) => (
                                <option key={size.id} value={size.id}>{size.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label htmlFor="new-pkg-length" className={labelClass}>Length (cm)</label>
                        <input id="new-pkg-length" type="number" step="0.01" value={data.length} onChange={(event) => setData('length', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="new-pkg-width" className={labelClass}>Width (cm)</label>
                        <input id="new-pkg-width" type="number" step="0.01" value={data.width} onChange={(event) => setData('width', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="new-pkg-height" className={labelClass}>Height (cm)</label>
                        <input id="new-pkg-height" type="number" step="0.01" value={data.height} onChange={(event) => setData('height', event.target.value)} className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="new-pkg-weight" className={labelClass}>Weight (kg)</label>
                        <input id="new-pkg-weight" type="number" step="0.001" value={data.weight_kg} onChange={(event) => setData('weight_kg', event.target.value)} required className={fieldClass} />
                    </div>
                    <div>
                        <label htmlFor="new-pkg-description" className={labelClass}>Description</label>
                        <input id="new-pkg-description" value={data.description} onChange={(event) => setData('description', event.target.value)} className={fieldClass} />
                    </div>
                    <button type="submit" disabled={processing} className="col-span-2 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60 sm:col-span-4">Add package</button>
                </form>
            )}

            <div className="mt-4 overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="text-xs uppercase tracking-wider text-slate-500">
                        <tr><th className="py-2 pr-4">#</th><th className="py-2 pr-4">Barcode</th><th className="py-2 pr-4">Box</th><th className="py-2 pr-4">Weight</th><th className="py-2 pr-4">Volumetric</th><th className="py-2" /></tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {shipment.packages.map((pkg) => (
                            <tr key={pkg.id}>
                                <td className="py-2 pr-4">{pkg.package_number}</td>
                                <td className="py-2 pr-4 font-mono text-xs text-slate-400">{pkg.barcode}</td>
                                <td className="py-2 pr-4 text-slate-400">{pkg.box_size ? `${pkg.box_size.box.name} · ${pkg.box_size.name}` : 'Custom'}</td>
                                <td className="py-2 pr-4">{pkg.weight_kg} kg</td>
                                <td className="py-2 pr-4 text-slate-400">{pkg.volumetric_weight_kg} kg</td>
                                <td className="py-2 text-right"><button onClick={() => remove(pkg.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </article>
    );
}

const legStatusColor: Record<string, string> = {
    planned: 'text-slate-400 border-white/10',
    loaded: 'text-cyan-300 border-cyan-400/30',
    departed: 'text-blue-300 border-blue-400/30',
    arrived: 'text-amber-300 border-amber-400/30',
    completed: 'text-emerald-300 border-emerald-400/30',
    cancelled: 'text-rose-300 border-rose-400/30',
};

const legTransitions: Record<string, string[]> = {
    planned: ['loaded', 'cancelled'],
    loaded: ['departed', 'cancelled'],
    departed: ['arrived'],
    arrived: ['completed'],
    completed: [],
    cancelled: [],
};

function RouteLegsCard({ shipment }: { shipment: Shipment }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ mode: 'road' as 'air' | 'sea' | 'road' | 'courier', origin_location: '', destination_location: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipment.id}/legs`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    const remove = (legId: number) => {
        if (confirm('Remove this leg?')) {
            router.delete(`/shipments/${shipment.id}/legs/${legId}`);
        }
    };

    const transition = (legId: number, status: string) => {
        router.post(`/shipments/${shipment.id}/legs/${legId}/transitions`, { status }, { preserveScroll: true });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Route legs</p>
                <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Add leg'}</button>
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <select value={data.mode} onChange={(event) => setData('mode', event.target.value as typeof data.mode)} className={fieldClass}>
                        <option value="road">Road</option>
                        <option value="air">Air</option>
                        <option value="sea">Sea</option>
                        <option value="courier">Courier</option>
                    </select>
                    <input placeholder="Origin" value={data.origin_location} onChange={(event) => setData('origin_location', event.target.value)} required className={fieldClass} />
                    <input placeholder="Destination" value={data.destination_location} onChange={(event) => setData('destination_location', event.target.value)} required className={fieldClass} />
                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Add leg</button>
                </form>
            )}

            <div className="mt-4 space-y-3">
                {shipment.route_legs.map((leg) => (
                    <div key={leg.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm">
                        <div>
                            <p className="font-medium">Leg {leg.sequence} · <span className="uppercase text-slate-500">{leg.mode}</span></p>
                            <p className="text-xs text-slate-500">{leg.origin_location} → {leg.destination_location}</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${legStatusColor[leg.status] ?? 'border-white/10 text-slate-400'}`}>{leg.status}</span>
                            {legTransitions[leg.status]?.length > 0 && (
                                <select
                                    defaultValue=""
                                    onChange={(event) => { if (event.target.value) transition(leg.id, event.target.value); event.target.value = ''; }}
                                    className="rounded-lg border border-white/10 bg-transparent px-2 py-1 text-xs text-slate-400"
                                >
                                    <option value="">Advance…</option>
                                    {legTransitions[leg.status].map((status) => (
                                        <option key={status} value={status}>{status.replace(/_/g, ' ')}</option>
                                    ))}
                                </select>
                            )}
                            {leg.status === 'planned' && (
                                <button onClick={() => remove(leg.id)} className="text-xs text-rose-400 hover:text-rose-300">Remove</button>
                            )}
                        </div>
                    </div>
                ))}
                {shipment.route_legs.length === 0 && <p className="text-sm text-slate-500">No route legs planned yet.</p>}
            </div>
        </article>
    );
}

const clearanceStatusColor: Record<string, string> = {
    pending: 'text-slate-400 border-white/10',
    under_review: 'text-blue-300 border-blue-400/30',
    cleared: 'text-emerald-300 border-emerald-400/30',
    held: 'text-amber-300 border-amber-400/30',
    rejected: 'text-rose-300 border-rose-400/30',
};

function CustomsCard({ shipment }: { shipment: Shipment }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, reset } = useForm({ declaration_number: '', customs_office: '', notes: '' });

    const hasOpenClearance = shipment.customs_clearances.some((clearance) => clearance.status !== 'cleared' && clearance.status !== 'rejected');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipment.id}/customs-clearances`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Customs</p>
                {!hasOpenClearance && (
                    <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Open clearance'}</button>
                )}
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 grid grid-cols-2 gap-3">
                    <input placeholder="Declaration number" value={data.declaration_number} onChange={(event) => setData('declaration_number', event.target.value)} className={fieldClass} />
                    <input placeholder="Customs office" value={data.customs_office} onChange={(event) => setData('customs_office', event.target.value)} className={fieldClass} />
                    <textarea placeholder="Notes (optional)" rows={2} value={data.notes} onChange={(event) => setData('notes', event.target.value)} className={`${fieldClass} col-span-2`} />
                    <button type="submit" disabled={processing} className="col-span-2 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Open clearance</button>
                </form>
            )}

            <div className="mt-4 space-y-2">
                {shipment.customs_clearances.map((clearance) => (
                    <Link
                        key={clearance.id}
                        href={`/shipments/${shipment.id}/customs-clearances/${clearance.id}`}
                        className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm hover:border-cyan-400/30"
                    >
                        <div>
                            <p className="font-medium">{clearance.declaration_number ?? `Clearance #${clearance.id}`}</p>
                            <p className="text-xs text-slate-500">{clearance.customs_office ?? '—'}</p>
                        </div>
                        <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${clearanceStatusColor[clearance.status] ?? 'border-white/10 text-slate-400'}`}>{clearance.status.replace(/_/g, ' ')}</span>
                    </Link>
                ))}
                {shipment.customs_clearances.length === 0 && <p className="text-sm text-slate-500">No customs clearances yet.</p>}
            </div>
        </article>
    );
}

const deliveryStatusColor: Record<string, string> = {
    assigned: 'text-slate-400 border-white/10',
    out_for_delivery: 'text-blue-300 border-blue-400/30',
    delivered: 'text-emerald-300 border-emerald-400/30',
    cancelled: 'text-rose-300 border-rose-400/30',
};

function DeliveryCard({ shipment }: { shipment: Shipment }) {
    const [showForm, setShowForm] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ driver_id: '' as number | '' });

    const hasOpenAssignment = shipment.delivery_assignments.some((assignment) => assignment.status !== 'delivered' && assignment.status !== 'cancelled');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/shipments/${shipment.id}/delivery-assignments`, { onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Local delivery</p>
                {!hasOpenAssignment && (
                    <button onClick={() => setShowForm((value) => !value)} className="text-xs text-cyan-300 hover:text-cyan-200">{showForm ? 'Cancel' : '+ Assign driver'}</button>
                )}
            </div>

            {showForm && (
                <form onSubmit={submit} className="mt-4 flex gap-2">
                    <input
                        placeholder="Driver ID (leave blank to auto-assign)"
                        value={data.driver_id}
                        onChange={(event) => setData('driver_id', event.target.value ? Number(event.target.value) : '')}
                        className={fieldClass}
                    />
                    <button type="submit" disabled={processing} className="shrink-0 rounded-lg bg-cyan-400 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Assign</button>
                </form>
            )}
            {errors.driver_id && <p className="mt-1 text-xs text-rose-400">{errors.driver_id}</p>}

            <div className="mt-4 space-y-2">
                {shipment.delivery_assignments.map((assignment) => (
                    <Link
                        key={assignment.id}
                        href={`/shipments/${shipment.id}/delivery-assignments/${assignment.id}`}
                        className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-sm hover:border-cyan-400/30"
                    >
                        <span>Delivery #{assignment.id}{assignment.scheduled_date ? ` · ${assignment.scheduled_date}` : ''}</span>
                        <span className={`rounded-full border px-2 py-0.5 text-xs capitalize ${deliveryStatusColor[assignment.status] ?? 'border-white/10 text-slate-400'}`}>{assignment.status.replace(/_/g, ' ')}</span>
                    </Link>
                ))}
                {shipment.delivery_assignments.length === 0 && <p className="text-sm text-slate-500">No delivery assigned yet.</p>}
            </div>
        </article>
    );
}

function TimelineCard({ shipment, statuses }: { shipment: Shipment; statuses: Record<string, { name: string; color: string }> }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Tracking timeline</p>
            <div className="mt-4 space-y-4">
                {shipment.tracking_events.map((event) => (
                    <div key={event.id} className="border-l-2 border-cyan-400/30 pl-4">
                        <div className="flex items-center gap-2">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${statusDotClass(statuses[event.to_status]?.color)}`} />
                            <p className="text-sm font-medium">{statuses[event.to_status]?.name ?? event.to_status.replace(/_/g, ' ')}</p>
                            {!event.is_public && <span className="rounded-full border border-amber-400/30 px-1.5 py-0.5 text-[10px] text-amber-300">Internal</span>}
                        </div>
                        {event.location && <p className="text-xs text-slate-400">{event.location}</p>}
                        {event.description && <p className="text-xs text-slate-500">{event.description}</p>}
                        <p className="mt-1 text-xs text-slate-600">{event.created_by?.name ?? 'System'} · {new Date(event.occurred_at).toLocaleString()}</p>
                    </div>
                ))}
                {shipment.tracking_events.length === 0 && <p className="text-sm text-slate-500">No tracking events yet.</p>}
            </div>
        </article>
    );
}

function TransitionCard({ shipment, allowedTransitions }: { shipment: Shipment; allowedTransitions: { value: string; label: string; color: string }[] }) {
    const { data, setData, post, processing, errors, reset, transform } = useForm({ status: '', location: '', description: '', is_public: true as boolean, occurred_at: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        transform((values) => ({ ...values, occurred_at: values.occurred_at ? new Date(values.occurred_at).toISOString() : '' }));
        post(`/shipments/${shipment.id}/transitions`, { onSuccess: () => reset() });
    };

    if (allowedTransitions.length === 0) {
        return (
            <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                <p className="text-sm font-semibold">Status</p>
                <p className="mt-3 text-sm text-slate-500">This shipment is in a terminal state; no further transitions are possible.</p>
            </article>
        );
    }

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Update status</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label className={labelClass}>New status</label>
                    <select value={data.status} onChange={(event) => setData('status', event.target.value)} required className={fieldClass}>
                        <option value="" disabled>Select a status…</option>
                        {allowedTransitions.map((transition) => (
                            <option key={transition.value} value={transition.value}>{transition.label}</option>
                        ))}
                    </select>
                    {errors.status && <p className="mt-1 text-xs text-rose-400">{errors.status}</p>}
                </div>
                <input placeholder="Location" value={data.location} onChange={(event) => setData('location', event.target.value)} className={fieldClass} />
                <div>
                    <label htmlFor="status-occurred-at" className={labelClass}>Status date and time (optional)</label>
                    <input id="status-occurred-at" type="datetime-local" value={data.occurred_at} onChange={(event) => setData('occurred_at', event.target.value)} className={fieldClass} />
                    <p className="mt-1 text-xs text-slate-400">When did this happen? Select an earlier date for a late update, or leave blank to use now. Time zone: {Intl.DateTimeFormat().resolvedOptions().timeZone}.</p>
                    {errors.occurred_at && <p className="mt-1 text-xs text-rose-400">{errors.occurred_at}</p>}
                </div>
                <textarea placeholder="Note (optional)" rows={2} value={data.description} onChange={(event) => setData('description', event.target.value)} className={fieldClass} />
                <label className="flex items-center gap-2 text-xs text-slate-400">
                    <input type="checkbox" checked={data.is_public} onChange={(event) => setData('is_public', event.target.checked)} className="size-3.5 rounded border-white/20 bg-white/5 text-cyan-400" />
                    Visible on public tracking
                </label>
                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">Apply</button>
            </form>
        </article>
    );
}

function BarcodeCard({ shipment, trackingUrl }: { shipment: Shipment; trackingUrl: string }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6 text-center">
            <p className="text-left text-sm font-semibold">Labels</p>
            <img src={`/shipments/${shipment.id}/barcode.svg`} alt={`Barcode for ${shipment.tracking_number}`} className="mx-auto mt-4 h-16 bg-white p-2" />
            <img src={`/shipments/${shipment.id}/qr.svg`} alt="Public tracking QR code" className="mx-auto mt-4 size-40 rounded-lg bg-white p-2" />
            <a href={trackingUrl} target="_blank" rel="noreferrer" className="mt-3 block text-xs text-cyan-300 hover:text-cyan-200">Open public tracking page ↗</a>
        </article>
    );
}
