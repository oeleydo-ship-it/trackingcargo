import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import { statusBadgeClass } from '../../lib/statusColors';
import { paymentModes } from '../../lib/paymentModes';
import { formatKg } from '../../lib/weight';
import { renderTrackingNumber, resolveTrackingFormat } from '../../lib/trackingNumber';
import CustomerCombobox from '../../components/CustomerCombobox';
import CarrierSelect from '../../components/CarrierSelect';
import CountrySelect from '../../components/CountrySelect';
import ShipmentFilterBar, { activeShipmentFilters, hasActiveShipmentFilters } from '../../components/ShipmentFilterBar';
import type { Address, BatchOption, Box, BranchOption, CarrierOption, CustomerOption, Paginated, ShipmentFilterOptions, ShipmentFilters, ShipmentSummary, TrackingNumberMode, TrackingSettings } from '../../types';

interface ShipmentsIndexProps {
    shipments: Paginated<ShipmentSummary>;
    filters: ShipmentFilters;
    filterOptions: ShipmentFilterOptions;
    boxes: Box[];
    branches: BranchOption[];
    trackingSettings: TrackingSettings;
    openBatches: BatchOption[];
    carriers: CarrierOption[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

type BookedPartyRole = 'consignor' | 'consignee';

interface PartyAddressRow {
    line1: string;
    line2: string;
    city: string;
    state: string;
    postal_code: string;
    country_code: string;
    contact_name: string;
    contact_phone: string;
}

interface PartyRow {
    role: BookedPartyRole;
    customer_id: number | '';
    name: string;
    company_name: string;
    email: string;
    phone: string;
    tax_id: string;
    address: PartyAddressRow;
}

const emptyAddress: PartyAddressRow = {
    line1: '', line2: '', city: '', state: '', postal_code: '', country_code: '', contact_name: '', contact_phone: '',
};

/**
 * The distinction clerks kept getting wrong: the consignor is the customer
 * sending the goods, never the courier moving them. The carrier is a separate
 * field entirely, above.
 */
const partyCopy: Record<BookedPartyRole, { title: string; hint: string }> = {
    consignor: {
        title: 'Consignor — sender',
        hint: 'Who the cargo comes from. Not the courier or forwarder.',
    },
    consignee: {
        title: 'Consignee — receiver',
        hint: 'Who receives the cargo in the destination country. Address required.',
    },
};

/** The address most likely meant for this role: pickup for a consignor, delivery for a consignee. */
function preferredAddress(customer: CustomerOption, role: BookedPartyRole): Address | null {
    const wanted = role === 'consignee' ? 'delivery' : 'pickup';

    return customer.addresses.find((address) => address.type === wanted)
        ?? customer.addresses.find((address) => address.is_default)
        ?? customer.addresses[0]
        ?? null;
}

function toAddressRow(address: Address): PartyAddressRow {
    return {
        line1: address.line1,
        line2: address.line2 ?? '',
        city: address.city,
        state: address.state ?? '',
        postal_code: address.postal_code ?? '',
        country_code: address.country_code,
        contact_name: address.contact_name ?? '',
        contact_phone: address.contact_phone ?? '',
    };
}

interface PackageRow {
    weight_kg: string;
    /** Identical pieces this row stands for; weight is per piece. */
    pieces: number;
    box_id: number | '';
    box_size_id: number | '';
    length: string;
    width: string;
    height: string;
}

const emptyPackage: PackageRow = { weight_kg: '', pieces: 1, box_id: '', box_size_id: '', length: '', width: '', height: '' };

const emptyParty = { customer_id: '' as number | '', name: '', company_name: '', email: '', phone: '', tax_id: '' };

const defaultParties: PartyRow[] = [
    { role: 'consignor', ...emptyParty, address: { ...emptyAddress } },
    { role: 'consignee', ...emptyParty, address: { ...emptyAddress } },
];

/**
 * The numbering method a booking starts with: the branch's own choice, else the
 * company's. "Enter the entire number" only counts while the company still
 * allows manual numbers, so a default set earlier cannot strand the form on a
 * method the server would refuse.
 */
function startingTrackingMode(settings: TrackingSettings, branchId: number | ''): TrackingNumberMode {
    const mode = settings.branchModes.find((row) => row.branch_id === branchId)?.mode ?? settings.defaultMode;

    return mode === 'full' && !settings.allowManual ? 'auto' : mode;
}

export default function ShipmentsIndex({ shipments, filters, filterOptions, boxes, branches, trackingSettings, openBatches, carriers }: ShipmentsIndexProps) {
    // "+ New shipment" on a shipment's own page links here with ?new=1 so the
    // booking form is already open when the list loads.
    const [showForm, setShowForm] = useState(() => new URLSearchParams(window.location.search).get('new') === '1');
    const filtering = hasActiveShipmentFilters(filters);
    // A page link has to carry the search and filters, or paging would quietly drop them.
    const goToPage = (page: number) => router.get('/shipments', { ...activeShipmentFilters(filters), page }, { preserveState: true });
    // Which customer each party is linked to, held outside useForm because the
    // server only wants customer_id — this is just what the picker renders.
    const [linkedCustomers, setLinkedCustomers] = useState<(CustomerOption | null)[]>([null, null]);
    const initialBranchId = (branches.length === 1 ? branches[0].id : '') as number | '';
    const initialTrackingMode = startingTrackingMode(trackingSettings, initialBranchId);
    const [manualTracking, setManualTracking] = useState(initialTrackingMode === 'full');
    // Once the clerk picks a method themselves, choosing another branch stops resetting it.
    const [methodChosen, setMethodChosen] = useState(false);
    const [newBatch, setNewBatch] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: initialBranchId,
        tracking_number: '',
        tracking_mode: initialTrackingMode as string,
        tracking_suffix: '',
        batch_id: '' as number | '',
        new_batch_reference: '',
        mode: 'air' as 'air' | 'sea' | 'road' | 'courier',
        carrier_id: '' as number | '',
        payment_mode: '',
        destination_country_code: '',
        destination_city: '',
        parties: defaultParties,
        packages: [{ ...emptyPackage }] as PackageRow[],
    });

    const selectedBranch = branches.find((branch) => branch.id === data.branch_id) ?? null;

    // Mirrors what ShipmentService will allocate. The running number is not
    // known until the row is written, so the sequence renders as placeholders.
    // The pattern can differ per branch and mode (Settings → Tracking numbers),
    // so the preview resolves the same rule the server will.
    const trackingPattern = resolveTrackingFormat(
        trackingSettings.rules ?? [],
        selectedBranch?.id ?? null,
        data.mode,
        { format: trackingSettings.format, padding: trackingSettings.padding },
    );

    const trackingPreview = selectedBranch
        ? renderTrackingNumber({
            format: trackingPattern.format,
            companyCode: trackingSettings.companyCode,
            branchPrefix: selectedBranch.tracking_prefix,
            padding: trackingPattern.padding,
        })
        : null;

    // A batch only holds shipments from its own branch, so the picker follows
    // the branch selection rather than listing every open batch.
    const branchBatches = openBatches.filter((batch) => batch.branch_id === data.branch_id);

    const toggleNewBatch = () => {
        setNewBatch((current) => {
            setData((previous) => ({ ...previous, batch_id: '', new_batch_reference: '' }));

            return !current;
        });
    };

    const changeTrackingMode = (mode: string) => {
        setMethodChosen(true);
        setManualTracking(mode === 'full');
        setData((previous) => ({ ...previous, tracking_mode: mode, tracking_number: '', tracking_suffix: '' }));
    };

    // Each branch can start with its own numbering method (Settings → Tracking numbers).
    const changeBranch = (branchId: number | '') => {
        if (methodChosen) {
            setData('branch_id', branchId);

            return;
        }

        const mode = startingTrackingMode(trackingSettings, branchId);
        setManualTracking(mode === 'full');
        setData((previous) => ({ ...previous, branch_id: branchId, tracking_mode: mode, tracking_number: '', tracking_suffix: '' }));
    };

    const setParties = (updater: (current: PartyRow[]) => PartyRow[]) => setData('parties', updater(data.parties));

    const patchParty = (index: number, patch: Partial<PartyRow>) =>
        setParties((current) => current.map((party, i) => (i === index ? { ...party, ...patch } : party)));

    const patchPartyAddress = (index: number, patch: Partial<PartyAddressRow>) =>
        setParties((current) => current.map((party, i) => (i === index ? { ...party, address: { ...party.address, ...patch } } : party)));

    // Picking a known customer copies their details onto the party. The copy is
    // deliberate: the shipment keeps the details as they were at booking, so
    // later edits to the customer's record cannot rewrite a shipment already
    // moving. Clearing the picker leaves whatever was copied, so a clerk can
    // start from a customer and then adjust.
    const linkCustomer = (index: number, customer: CustomerOption) => {
        setLinkedCustomers((current) => current.map((linked, i) => (i === index ? customer : linked)));

        const address = preferredAddress(customer, data.parties[index].role);

        patchParty(index, {
            customer_id: customer.id,
            name: customer.name,
            company_name: customer.company_name ?? '',
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            tax_id: customer.tax_id ?? '',
            ...(address ? { address: toAddressRow(address) } : {}),
        });
    };

    // Unlinking keeps whatever was copied on the form: the clerk is usually
    // correcting one detail of a known customer, not starting over.
    const unlinkCustomer = (index: number) => {
        setLinkedCustomers((current) => current.map((linked, i) => (i === index ? null : linked)));
        patchParty(index, { customer_id: '' });
    };

    // Inertia keys nested errors as "parties.0.address.line1", which useForm's
    // per-field error typing does not cover.
    const fieldErrors = errors as Record<string, string | undefined>;
    const setPackages = (updater: (current: PackageRow[]) => PackageRow[]) => setData('packages', updater(data.packages));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/shipments', {
            onSuccess: () => {
                reset();
                setLinkedCustomers([null, null]);
                setManualTracking(initialTrackingMode === 'full');
                setMethodChosen(false);
                setNewBatch(false);
                setShowForm(false);
            },
        });
    };

    return (
        <AppLayout title="Shipments">
            <Head title="Shipments" />

            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-slate-400">{shipments.total} shipment{shipments.total === 1 ? '' : 's'}{filtering ? ' match your search' : ''}</p>
                <button onClick={() => setShowForm((value) => !value)} className="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300">
                    {showForm ? 'Cancel' : 'New shipment'}
                </button>
            </div>

            {/* Searching and booking are separate jobs: the filters step aside while a shipment is being booked. */}
            {!showForm && <ShipmentFilterBar filters={filters} options={filterOptions} />}

            {showForm && (
                <form onSubmit={submit} className="mb-6 space-y-5 rounded-2xl border border-white/10 bg-white/[0.035] p-6">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label htmlFor="branch_id" className={labelClass}>Branch</label>
                            <select id="branch_id" value={data.branch_id} onChange={(event) => changeBranch(event.target.value ? Number(event.target.value) : '')} required className={fieldClass}>
                                <option value="">Select a branch…</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>{branch.name} ({branch.code})</option>
                                ))}
                            </select>
                            {errors.branch_id && <p className="mt-1 text-xs text-rose-400">{errors.branch_id}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Mode</label>
                            <select value={data.mode} onChange={(event) => setData('mode', event.target.value as typeof data.mode)} className={fieldClass}>
                                <option value="air">Air</option>
                                <option value="sea">Sea</option>
                                <option value="road">Road</option>
                                <option value="courier">Courier</option>
                            </select>
                        </div>
                        <div>
                            <label className={labelClass}>Destination country</label>
                            <CountrySelect aria-label="Destination country" value={data.destination_country_code} onChange={(event) => setData('destination_country_code', event.target.value)} required className={fieldClass} />
                            {errors.destination_country_code && <p className="mt-1 text-xs text-rose-400">{errors.destination_country_code}</p>}
                        </div>
                        <div>
                            <label className={labelClass}>Destination city</label>
                            <input value={data.destination_city} onChange={(event) => setData('destination_city', event.target.value)} className={fieldClass} placeholder="Manila" />
                        </div>
                        <div>
                            <CarrierSelect
                                label="Carrier (optional)"
                                carriers={carriers}
                                mode={data.mode}
                                value={data.carrier_id}
                                onChange={(value) => setData('carrier_id', value)}
                                error={errors.carrier_id}
                            />
                        </div>
                        <div>
                            <label htmlFor="payment_mode" className={labelClass}>Mode of payment (optional)</label>
                            <select id="payment_mode" value={data.payment_mode} onChange={(event) => setData('payment_mode', event.target.value)} className={fieldClass}>
                                <option value="">Not specified</option>
                                {paymentModes.map((mode) => <option key={mode.value} value={mode.value}>{mode.label}</option>)}
                            </select>
                            {errors.payment_mode && <p className="mt-1 text-xs text-rose-400">{errors.payment_mode}</p>}
                        </div>
                    </div>

                    <div className="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium text-slate-400">Batch</p>
                                <p className="mt-1 text-xs text-slate-600">Group this shipment with others so they can be status-updated together.</p>
                            </div>
                            <button type="button" onClick={toggleNewBatch} className="text-xs text-cyan-300 hover:text-cyan-200">
                                {newBatch ? 'Pick an existing batch' : 'Start a new batch'}
                            </button>
                        </div>

                        <div className="mt-3">
                            {newBatch ? (
                                <>
                                    <label htmlFor="new_batch_reference" className={labelClass}>New batch reference</label>
                                    <input
                                        id="new_batch_reference"
                                        value={data.new_batch_reference}
                                        onChange={(event) => setData('new_batch_reference', event.target.value)}
                                        className={fieldClass}
                                        placeholder="Tuesday air consolidation"
                                    />
                                    <p className="mt-1 text-xs text-slate-600">The batch number is generated from your Settings → Batches pattern.</p>
                                </>
                            ) : (
                                <>
                                    <label htmlFor="batch_id" className={labelClass}>Open batch</label>
                                    <select
                                        id="batch_id"
                                        value={data.batch_id}
                                        disabled={!data.branch_id}
                                        onChange={(event) => setData('batch_id', event.target.value ? Number(event.target.value) : '')}
                                        className={`${fieldClass} disabled:cursor-not-allowed disabled:opacity-40`}
                                    >
                                        <option value="">
                                            {data.branch_id ? 'No batch' : 'Choose a branch first'}
                                        </option>
                                        {branchBatches.map((batch) => (
                                            <option key={batch.id} value={batch.id}>
                                                {batch.batch_number}{batch.reference ? ` — ${batch.reference}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {data.branch_id !== '' && branchBatches.length === 0 && (
                                        <p className="mt-1 text-xs text-slate-600">No open batches in this branch yet — start a new one instead.</p>
                                    )}
                                </>
                            )}
                            {errors.batch_id && <p className="mt-1 text-xs text-rose-400">{errors.batch_id}</p>}
                            {errors.new_batch_reference && <p className="mt-1 text-xs text-rose-400">{errors.new_batch_reference}</p>}
                        </div>
                    </div>

                    <div className="rounded-xl border border-white/10 bg-white/[0.02] p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium text-slate-400">Tracking number</p>
                                <p className="mt-1 font-mono text-sm text-cyan-200">
                                    {manualTracking
                                        ? data.tracking_number || 'Enter a tracking number below'
                                        : data.tracking_mode === 'suffix' && trackingPreview
                                            ? trackingPreview.replace(/#+/g, data.tracking_suffix || '[receipt/reference]')
                                            : trackingPreview ?? 'Select a branch to see the generated number'}
                                </p>
                            </div>
                            <div>
                                <label htmlFor="tracking_mode" className={labelClass}>Numbering method</label>
                                <select id="tracking_mode" value={data.tracking_mode} onChange={(event) => changeTrackingMode(event.target.value)} className={fieldClass}>
                                    <option value="auto">Generate automatically</option>
                                    <option value="suffix">Company / branch + receipt reference</option>
                                    {trackingSettings.allowManual && <option value="full">Enter entire tracking number</option>}
                                </select>
                            </div>
                        </div>

                        {data.tracking_mode === 'suffix' && (
                            <div className="mt-3">
                                <label htmlFor="tracking_suffix" className={labelClass}>Receipt or company reference</label>
                                <input id="tracking_suffix" value={data.tracking_suffix} onChange={(event) => setData('tracking_suffix', event.target.value.toUpperCase())} required maxLength={Math.max(1, 40 - (trackingPreview?.replace(/#+/g, '').length ?? 0))} className={`${fieldClass} font-mono`} placeholder="RECEIPT123 or 000045" />
                                <p className="mt-1 text-xs text-slate-400">Replaces the running-number section, keeping the configured prefix. Leading zeros are preserved. Use letters, digits, dashes, underscores or dots.</p>
                                {errors.tracking_suffix && <p className="mt-2 text-xs text-rose-400">{errors.tracking_suffix}</p>}
                            </div>
                        )}

                        {manualTracking && (
                            <div className="mt-3">
                                <label htmlFor="tracking_number" className={labelClass}>Custom tracking number</label>
                                <input
                                    id="tracking_number"
                                    value={data.tracking_number}
                                    onChange={(event) => setData('tracking_number', event.target.value.toUpperCase())}
                                    maxLength={40}
                                    className={`${fieldClass} font-mono`}
                                    placeholder={trackingPreview ?? 'ABC-DXB-00000001'}
                                />
                                <p className="mt-1 text-xs text-slate-600">Letters, digits, dashes, dots and underscores. Leave blank to fall back to the generated number.</p>
                            </div>
                        )}
                        {errors.tracking_number && <p className="mt-2 text-xs text-rose-400">{errors.tracking_number}</p>}
                    </div>

                    <div>
                        <p className={labelClass}>Parties</p>
                        <p className="-mt-1 mb-3 text-xs text-slate-600">
                            The consignor sends the cargo and the consignee receives it. The company moving it is the
                            carrier, set under Carrier above.
                        </p>
                        {errors.parties && <p className="mb-2 text-xs text-rose-400">{errors.parties}</p>}
                        <div className="grid gap-3 xl:grid-cols-2">
                            {data.parties.map((party, index) => (
                                <PartyFieldset
                                    key={party.role}
                                    party={party}
                                    index={index}
                                    errors={fieldErrors}
                                    linkedCustomer={linkedCustomers[index] ?? null}
                                    onChange={(patch) => patchParty(index, patch)}
                                    onAddressChange={(patch) => patchPartyAddress(index, patch)}
                                    onLinkCustomer={(customer) => linkCustomer(index, customer)}
                                    onUnlinkCustomer={() => unlinkCustomer(index)}
                                />
                            ))}
                        </div>
                    </div>

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <p className={labelClass}>Packages</p>
                            <button type="button" onClick={() => setPackages((current) => [...current, { ...emptyPackage }])} className="text-xs text-cyan-300 hover:text-cyan-200">+ Add package</button>
                        </div>
                        {errors.packages && <p className="mb-2 text-xs text-rose-400">{errors.packages}</p>}
                        <div className="space-y-3">
                            {data.packages.map((pkg, index) => {
                                const selectedBox = boxes.find((box) => box.id === pkg.box_id);

                                return (
                                    <div key={index} className="rounded-xl border border-white/10 p-3">
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-7">
                                            <div>
                                                <label htmlFor={`pkg-${index}-box`} className={labelClass}>Box</label>
                                                <select
                                                    id={`pkg-${index}-box`}
                                                    value={pkg.box_id}
                                                    onChange={(event) => {
                                                        const boxId = event.target.value ? Number(event.target.value) : '';
                                                        setPackages((current) => current.map((p, i) => (i === index ? { ...p, box_id: boxId, box_size_id: '' } : p)));
                                                    }}
                                                    className={fieldClass}
                                                >
                                                    <option value="">Custom (no box)</option>
                                                    {boxes.map((box) => (
                                                        <option key={box.id} value={box.id}>{box.name}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-size`} className={labelClass}>Size</label>
                                                <select
                                                    id={`pkg-${index}-size`}
                                                    value={pkg.box_size_id}
                                                    disabled={!selectedBox}
                                                    onChange={(event) => {
                                                        const boxSizeId = event.target.value ? Number(event.target.value) : '';
                                                        const size = selectedBox?.sizes.find((s) => s.id === boxSizeId);
                                                        setPackages((current) => current.map((p, i) => (i === index
                                                            ? {
                                                                ...p,
                                                                box_size_id: boxSizeId,
                                                                length: size ? (size.length_cm ?? '') : p.length,
                                                                width: size ? (size.width_cm ?? '') : p.width,
                                                                height: size ? (size.height_cm ?? '') : p.height,
                                                            }
                                                            : p)));
                                                    }}
                                                    className={`${fieldClass} disabled:cursor-not-allowed disabled:opacity-40`}
                                                >
                                                    <option value="">{selectedBox ? 'Select a size…' : 'Choose a box first'}</option>
                                                    {selectedBox?.sizes.map((size) => (
                                                        <option key={size.id} value={size.id}>{size.name}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-pieces`} className={labelClass}>No. of pcs</label>
                                                <input
                                                    id={`pkg-${index}-pieces`}
                                                    value={pkg.pieces}
                                                    onChange={(event) => setPackages((current) => current.map((p, i) => (i === index ? { ...p, pieces: Number(event.target.value) } : p)))}
                                                    type="number"
                                                    min={1}
                                                    max={999}
                                                    required
                                                    className={fieldClass}
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-length`} className={labelClass}>Length (cm)</label>
                                                <input
                                                    id={`pkg-${index}-length`}
                                                    value={pkg.length}
                                                    onChange={(event) => setPackages((current) => current.map((p, i) => (i === index ? { ...p, length: event.target.value } : p)))}
                                                    type="number"
                                                    step="0.01"
                                                    className={fieldClass}
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-width`} className={labelClass}>Width (cm)</label>
                                                <input
                                                    id={`pkg-${index}-width`}
                                                    value={pkg.width}
                                                    onChange={(event) => setPackages((current) => current.map((p, i) => (i === index ? { ...p, width: event.target.value } : p)))}
                                                    type="number"
                                                    step="0.01"
                                                    className={fieldClass}
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-height`} className={labelClass}>Height (cm)</label>
                                                <input
                                                    id={`pkg-${index}-height`}
                                                    value={pkg.height}
                                                    onChange={(event) => setPackages((current) => current.map((p, i) => (i === index ? { ...p, height: event.target.value } : p)))}
                                                    type="number"
                                                    step="0.01"
                                                    className={fieldClass}
                                                />
                                            </div>
                                            <div>
                                                <label htmlFor={`pkg-${index}-weight`} className={labelClass}>Weight (kg, optional)</label>
                                                <input
                                                    id={`pkg-${index}-weight`}
                                                    value={pkg.weight_kg}
                                                    onChange={(event) => setPackages((current) => current.map((p, i) => (i === index ? { ...p, weight_kg: event.target.value } : p)))}
                                                    type="number"
                                                    step="0.001"
                                                    min="0.001"
                                                    className={fieldClass}
                                                />
                                            </div>
                                        </div>
                                        {data.packages.length > 1 && (
                                            <button type="button" onClick={() => setPackages((current) => current.filter((_, i) => i !== index))} className="mt-2 text-xs text-rose-400 hover:text-rose-300">Remove package</button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <button type="submit" disabled={processing} className="rounded-lg bg-cyan-400 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-300 disabled:opacity-60">
                        {processing ? 'Creating…' : 'Create shipment'}
                    </button>
                </form>
            )}

            <div className="overflow-hidden rounded-2xl border border-white/10">
                <table className="w-full text-left text-sm">
                    <thead className="bg-white/[0.035] text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Tracking #</th>
                            <th className="px-4 py-3">Mode</th>
                            <th className="px-4 py-3">Destination</th>
                            <th className="px-4 py-3">Branch</th>
                            <th className="px-4 py-3">Weight</th>
                            <th className="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-white/5">
                        {shipments.data.map((shipment) => (
                            <tr key={shipment.id}>
                                <td className="px-4 py-3 font-mono text-xs">
                                    <Link href={`/shipments/${shipment.id}`} className="font-semibold text-cyan-300 hover:text-cyan-200">{shipment.tracking_number}</Link>
                                </td>
                                <td className="px-4 py-3 text-slate-400 uppercase">{shipment.mode}</td>
                                <td className="px-4 py-3 text-slate-400">{[shipment.destination_city, shipment.destination_country_code].filter(Boolean).join(', ') || '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{shipment.branch?.name ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-400">{formatKg(shipment.chargeable_weight_kg)}</td>
                                <td className="px-4 py-3"><span className={`rounded-full border px-2 py-0.5 text-xs ${statusBadgeClass(shipment.shipment_status?.color)}`}>{shipment.shipment_status?.name ?? shipment.status.replace(/_/g, ' ')}</span></td>
                            </tr>
                        ))}
                        {shipments.data.length === 0 && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">{filtering ? 'No shipments match your search or filters.' : 'No shipments yet.'}</td></tr>
                        )}
                    </tbody>
                </table>
            </div>

            {shipments.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-xs text-slate-500">
                    <span>{shipments.from ?? 0}–{shipments.to ?? 0} of {shipments.total}</span>
                    <div className="flex gap-2">
                        <button disabled={shipments.current_page <= 1} onClick={() => goToPage(shipments.current_page - 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Previous</button>
                        <button disabled={shipments.current_page >= shipments.last_page} onClick={() => goToPage(shipments.current_page + 1)} className="rounded-lg border border-white/10 px-3 py-1.5 disabled:opacity-40">Next</button>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

interface PartyFieldsetProps {
    party: PartyRow;
    index: number;
    linkedCustomer: CustomerOption | null;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<PartyRow>) => void;
    onAddressChange: (patch: Partial<PartyAddressRow>) => void;
    onLinkCustomer: (customer: CustomerOption) => void;
    onUnlinkCustomer: () => void;
}

/**
 * One party on the booking form: who they are, which customer on file they are
 * if any, and where they are.
 *
 * The consignee's address is required — it is where the cargo has to end up —
 * so those fields are marked required here and enforced again server-side in
 * StoreShipmentRequest.
 */
function PartyFieldset({ party, index, linkedCustomer, errors, onChange, onAddressChange, onLinkCustomer, onUnlinkCustomer }: PartyFieldsetProps) {
    const copy = partyCopy[party.role];
    const addressRequired = party.role === 'consignee';
    const prefix = `parties.${index}`;

    const errorText = (field: string) => {
        const message = errors[`${prefix}.${field}`];

        return message ? <p className="mt-1 text-xs text-rose-400">{message}</p> : null;
    };

    return (
        <div className="rounded-xl border border-white/10 bg-white/[0.02] p-4">
            <div className="mb-3">
                <p className="text-xs font-semibold uppercase tracking-wider text-cyan-300">{copy.title}</p>
                <p className="mt-1 text-xs text-slate-600">{copy.hint}</p>
            </div>

            <div className="space-y-3">
                <div>
                    <label htmlFor={`${prefix}-customer`} className={labelClass}>Customer on file</label>
                    <CustomerCombobox
                        inputId={`${prefix}-customer`}
                        selected={linkedCustomer}
                        onSelect={onLinkCustomer}
                        onClear={onUnlinkCustomer}
                    />
                    <p className="mt-1 text-xs text-slate-600">
                        {linkedCustomer
                            ? 'Linked to this customer. The details below are this shipment’s own copy — edit them freely.'
                            : 'Type a few letters to search, or just fill the fields below for a one-off sender or receiver.'}
                    </p>
                    {errorText('customer_id')}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label htmlFor={`${prefix}-name`} className={labelClass}>Name</label>
                        <input id={`${prefix}-name`} value={party.name} onChange={(event) => onChange({ name: event.target.value })} placeholder="Full name" required className={fieldClass} />
                        {errorText('name')}
                    </div>
                    <div>
                        <label htmlFor={`${prefix}-company`} className={labelClass}>Company</label>
                        <input id={`${prefix}-company`} value={party.company_name} onChange={(event) => onChange({ company_name: event.target.value })} className={fieldClass} />
                        {errorText('company_name')}
                    </div>
                    <div>
                        <label htmlFor={`${prefix}-email`} className={labelClass}>Email</label>
                        <input id={`${prefix}-email`} type="email" value={party.email} onChange={(event) => onChange({ email: event.target.value })} className={fieldClass} />
                        {errorText('email')}
                    </div>
                    <div>
                        <label htmlFor={`${prefix}-phone`} className={labelClass}>Phone</label>
                        <input id={`${prefix}-phone`} value={party.phone} onChange={(event) => onChange({ phone: event.target.value })} className={fieldClass} />
                        {errorText('phone')}
                    </div>
                </div>

                <div className="rounded-lg border border-white/10 p-3">
                    <p className="mb-2 text-xs font-medium text-slate-400">
                        Address {addressRequired ? <span className="text-rose-400">*</span> : <span className="text-slate-600">(optional)</span>}
                    </p>

                    <div className="space-y-3">
                        <div>
                            <label htmlFor={`${prefix}-line1`} className={labelClass}>Address line 1</label>
                            <input id={`${prefix}-line1`} value={party.address.line1} onChange={(event) => onAddressChange({ line1: event.target.value })} required={addressRequired} placeholder="Street and number" className={fieldClass} />
                            {errorText('address.line1')}
                        </div>
                        <div>
                            <label htmlFor={`${prefix}-line2`} className={labelClass}>Address line 2</label>
                            <input id={`${prefix}-line2`} value={party.address.line2} onChange={(event) => onAddressChange({ line2: event.target.value })} placeholder="Building, unit, barangay" className={fieldClass} />
                            {errorText('address.line2')}
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <label htmlFor={`${prefix}-city`} className={labelClass}>City</label>
                                <input id={`${prefix}-city`} value={party.address.city} onChange={(event) => onAddressChange({ city: event.target.value })} required={addressRequired} className={fieldClass} />
                                {errorText('address.city')}
                            </div>
                            <div>
                                <label htmlFor={`${prefix}-state`} className={labelClass}>State</label>
                                <input id={`${prefix}-state`} value={party.address.state} onChange={(event) => onAddressChange({ state: event.target.value })} className={fieldClass} />
                                {errorText('address.state')}
                            </div>
                            <div>
                                <label htmlFor={`${prefix}-postal`} className={labelClass}>Postal code</label>
                                <input id={`${prefix}-postal`} value={party.address.postal_code} onChange={(event) => onAddressChange({ postal_code: event.target.value })} className={fieldClass} />
                                {errorText('address.postal_code')}
                            </div>
                            <div>
                                <label htmlFor={`${prefix}-country`} className={labelClass}>Country</label>
                                <CountrySelect id={`${prefix}-country`} value={party.address.country_code} onChange={(event) => onAddressChange({ country_code: event.target.value })} required={addressRequired} className={fieldClass} />
                                {errorText('address.country_code')}
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor={`${prefix}-contact-name`} className={labelClass}>Contact at address</label>
                                <input id={`${prefix}-contact-name`} value={party.address.contact_name} onChange={(event) => onAddressChange({ contact_name: event.target.value })} className={fieldClass} />
                                {errorText('address.contact_name')}
                            </div>
                            <div>
                                <label htmlFor={`${prefix}-contact-phone`} className={labelClass}>Contact phone</label>
                                <input id={`${prefix}-contact-phone`} value={party.address.contact_phone} onChange={(event) => onAddressChange({ contact_phone: event.target.value })} className={fieldClass} />
                                {errorText('address.contact_phone')}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
