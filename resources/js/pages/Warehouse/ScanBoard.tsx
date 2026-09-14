import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';
import type { PackageScan, SharedPageProps, Warehouse, WarehouseScanType } from '../../types';

interface ScanBoardProps {
    warehouse: Warehouse;
    recentScans: PackageScan[];
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

const scanTypeColor: Record<string, string> = {
    receive: 'text-cyan-300 border-cyan-400/30',
    sort: 'text-blue-300 border-blue-400/30',
    load: 'text-amber-300 border-amber-400/30',
    unload: 'text-amber-300 border-amber-400/30',
    dispatch: 'text-emerald-300 border-emerald-400/30',
};

function newIdempotencyKey(): string {
    return typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export default function ScanBoard({ warehouse, recentScans }: ScanBoardProps) {
    const { auth } = usePage<SharedPageProps>().props;
    const companyId = auth.user?.company?.id;
    const [feed, setFeed] = useState<PackageScan[]>(recentScans);

    useEffect(() => setFeed(recentScans), [recentScans]);

    useEffect(() => {
        if (!companyId || !window.Echo) {
            return;
        }

        const channelName = `company.${companyId}`;
        const channel = window.Echo.private(channelName);
        channel.listen('.PackageScanned', (event: PackageScan) => {
            setFeed((current) => (current.some((scan) => scan.id === event.id) ? current : [event, ...current].slice(0, 50)));
        });

        return () => {
            window.Echo?.leave(channelName);
        };
    }, [companyId]);

    return (
        <AppLayout title={`${warehouse.name} · Scan board`}>
            <Head title="Scan board" />

            <div className="mb-6">
                <p className="font-mono text-xs text-slate-500">{warehouse.code}</p>
                <h2 className="text-xl font-semibold">{warehouse.name} scan board</h2>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1fr_1.3fr]">
                <ScanForm warehouse={warehouse} />
                <LiveFeed feed={feed} />
            </div>
        </AppLayout>
    );
}

function ScanForm({ warehouse }: { warehouse: Warehouse }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        idempotency_key: newIdempotencyKey(),
        scan_type: 'receive' as WarehouseScanType,
        package_barcode: '',
        to_location_code: '',
        load_unit_id: '' as number | '',
    });

    const needsLocation = data.scan_type === 'receive' || data.scan_type === 'sort' || data.scan_type === 'unload';
    const needsLoadUnit = data.scan_type === 'load';
    const otherError = Object.entries(errors).find(([field]) => !['package_barcode', 'to_location_code', 'load_unit_id'].includes(field))?.[1];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/warehouses/${warehouse.id}/scans`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reset('package_barcode', 'to_location_code', 'load_unit_id');
                setData('idempotency_key', newIdempotencyKey());
            },
        });
    };

    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Scan a package</p>
            <form onSubmit={submit} className="mt-4 space-y-3">
                <div>
                    <label className={labelClass}>Scan type</label>
                    <select
                        value={data.scan_type}
                        onChange={(event) => setData('scan_type', event.target.value as WarehouseScanType)}
                        className={fieldClass}
                    >
                        <option value="receive">Receive</option>
                        <option value="sort">Sort</option>
                        <option value="load">Load onto unit</option>
                        <option value="unload">Unload from unit</option>
                        <option value="dispatch">Dispatch</option>
                    </select>
                </div>

                <div>
                    <label className={labelClass}>Package barcode</label>
                    <input
                        autoFocus
                        value={data.package_barcode}
                        onChange={(event) => setData('package_barcode', event.target.value)}
                        required
                        className={`${fieldClass} font-mono`}
                        placeholder="Scan or type barcode"
                    />
                    {errors.package_barcode && <p className="mt-1 text-xs text-rose-400">{errors.package_barcode}</p>}
                </div>

                {needsLocation && (
                    <div>
                        <label className={labelClass}>{data.scan_type === 'sort' ? 'Destination location' : 'Location'} code</label>
                        <input
                            value={data.to_location_code}
                            onChange={(event) => setData('to_location_code', event.target.value.toUpperCase())}
                            required
                            className={`${fieldClass} font-mono`}
                            placeholder="RCV-01"
                        />
                        {errors.to_location_code && <p className="mt-1 text-xs text-rose-400">{errors.to_location_code}</p>}
                    </div>
                )}

                {needsLoadUnit && (
                    <div>
                        <label className={labelClass}>Load unit ID</label>
                        <input
                            value={data.load_unit_id}
                            onChange={(event) => setData('load_unit_id', event.target.value ? Number(event.target.value) : '')}
                            required
                            className={fieldClass}
                            placeholder="e.g. 12"
                        />
                        {errors.load_unit_id && <p className="mt-1 text-xs text-rose-400">{errors.load_unit_id}</p>}
                    </div>
                )}

                {otherError && <p className="text-xs text-rose-400">{otherError}</p>}

                <button type="submit" disabled={processing} className="w-full rounded-lg bg-cyan-400 px-3 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-60">
                    {processing ? 'Submitting…' : 'Submit scan'}
                </button>
            </form>
        </article>
    );
}

function LiveFeed({ feed }: { feed: PackageScan[] }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-6">
            <p className="text-sm font-semibold">Live activity</p>
            <div className="mt-4 max-h-[32rem] space-y-2 overflow-y-auto">
                {feed.map((scan) => (
                    <div key={scan.id} className="flex items-center justify-between rounded-xl border border-white/5 p-3 text-xs">
                        <div>
                            <p className="font-mono text-slate-200">{scan.package_barcode}</p>
                            <p className="text-slate-500">
                                {scan.shipment_tracking_number}
                                {scan.from_location_code && ` · ${scan.from_location_code} →`}
                                {scan.to_location_code && ` ${scan.to_location_code}`}
                                {scan.scanned_by && ` · ${scan.scanned_by}`}
                            </p>
                        </div>
                        <div className="text-right">
                            <span className={`rounded-full border px-2 py-0.5 capitalize ${scanTypeColor[scan.scan_type] ?? 'border-white/10 text-slate-400'}`}>{scan.scan_type_label}</span>
                            <p className="mt-1 text-slate-600">{new Date(scan.occurred_at).toLocaleTimeString()}</p>
                        </div>
                    </div>
                ))}
                {feed.length === 0 && <p className="text-sm text-slate-500">No scans yet.</p>}
            </div>
        </article>
    );
}
