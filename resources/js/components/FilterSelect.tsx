const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';
const labelClass = 'mb-1.5 block text-xs font-medium text-slate-400';

export interface FilterChoice {
    value: string;
    label: string;
}

interface FilterSelectProps {
    id: string;
    label: string;
    value: string;
    allLabel: string;
    choices: FilterChoice[];
    onChange: (value: string) => void;
}

/** A labelled dropdown for a list filter, with an "All …" choice for no filter. */
export default function FilterSelect({ id, label, value, allLabel, choices, onChange }: FilterSelectProps) {
    // A value that arrived in the URL but is not among the options (a stale
    // bookmark, say) is still shown, so the control never claims "All" while a
    // filter is quietly applied.
    const shown = value !== '' && !choices.some((choice) => choice.value === value)
        ? [...choices, { value, label: value }]
        : choices;

    return (
        <div>
            <label htmlFor={id} className={labelClass}>{label}</label>
            <select id={id} value={value} onChange={(event) => onChange(event.target.value)} className={fieldClass}>
                <option value="">{allLabel}</option>
                {shown.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}
            </select>
        </div>
    );
}
