import { Link } from '@inertiajs/react';
import type { CarrierOption, ShipmentMode } from '../types';

interface CarrierSelectProps {
    label: string;
    carriers: CarrierOption[];
    /** The shipment's mode; carriers that declared modes are narrowed to it. */
    mode: ShipmentMode;
    value: number | '';
    onChange: (value: number | '') => void;
    error?: string;
}

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

/**
 * The carrier picker on the booking and edit forms, fed by Settings → Carriers.
 *
 * A carrier that lists the modes it serves is only offered for those modes; one
 * that lists none is offered for any. The currently selected carrier is always
 * kept in the list even if the mode no longer matches, so changing the mode
 * never silently blanks a choice the clerk made — the mismatch is flagged
 * instead.
 */
export default function CarrierSelect({ label, carriers, mode, value, onChange, error }: CarrierSelectProps) {
    const matchesMode = (carrier: CarrierOption) => carrier.modes === null || carrier.modes.length === 0 || carrier.modes.includes(mode);

    const options = carriers.filter((carrier) => matchesMode(carrier) || carrier.id === value);
    const selected = carriers.find((carrier) => carrier.id === value);

    return (
        <div>
            <label htmlFor="carrier_id" className={labelClass}>{label}</label>
            <select
                id="carrier_id"
                value={value}
                onChange={(event) => onChange(event.target.value ? Number(event.target.value) : '')}
                className={fieldClass}
            >
                <option value="">None</option>
                {options.map((carrier) => (
                    <option key={carrier.id} value={carrier.id}>
                        {carrier.name} ({carrier.code}){carrier.is_active ? '' : ' — switched off'}
                    </option>
                ))}
            </select>

            {selected && !matchesMode(selected) && (
                <p className="mt-1 text-xs text-amber-300">{selected.name} is not set up for {mode} shipments.</p>
            )}
            {carriers.length === 0 && (
                <p className="mt-1 text-xs text-slate-500">
                    No carriers yet. <Link href="/settings/carriers" className="text-cyan-300 hover:text-cyan-200">Add them in Settings → Carriers</Link>.
                </p>
            )}
            {error && <p className="mt-1 text-xs text-rose-400">{error}</p>}
        </div>
    );
}
