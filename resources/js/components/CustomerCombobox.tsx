import { useEffect, useId, useRef, useState } from 'react';
import type { CustomerOption } from '../types';

interface CustomerComboboxProps {
    /** The customer already linked, if any — shown instead of the search input. */
    selected: CustomerOption | null;
    onSelect: (customer: CustomerOption) => void;
    onClear: () => void;
    inputId?: string;
    placeholder?: string;
}

/** Matches CustomerLookupController::MIN_TERM_LENGTH — below this the server returns nothing. */
const MIN_TERM_LENGTH = 2;

/** Long enough that a typist is not firing a request per keystroke, short enough to feel live. */
const DEBOUNCE_MS = 250;

const fieldClass = 'w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10';

/**
 * Type-ahead picker over the customer book.
 *
 * Replaces a plain <select> of every customer, which does not survive a book
 * of any real size. The list is fetched as the clerk types and the whole
 * customer — contact details and address book — comes back with the match, so
 * choosing one can fill a party in without a second request.
 */
export default function CustomerCombobox({ selected, onSelect, onClear, inputId, placeholder }: CustomerComboboxProps) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<CustomerOption[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const containerRef = useRef<HTMLDivElement>(null);
    const generatedId = useId();
    const listboxId = `${inputId ?? generatedId}-listbox`;

    // Each keystroke supersedes the request in flight, so a slow response for
    // "ma" cannot land after — and overwrite — the results for "maha".
    useEffect(() => {
        const query = term.trim();

        if (query.length < MIN_TERM_LENGTH) {
            setResults([]);
            setLoading(false);

            return;
        }

        const controller = new AbortController();
        setLoading(true);

        const timer = window.setTimeout(() => {
            fetch(`/crm/customers/lookup?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((response) => (response.ok ? response.json() : { customers: [] }))
                .then((payload: { customers?: CustomerOption[] }) => {
                    setResults(payload.customers ?? []);
                    setActiveIndex(0);
                    setOpen(true);
                })
                .catch(() => {
                    // An aborted request is the expected path on every keystroke.
                })
                .finally(() => {
                    if (! controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    useEffect(() => {
        const onPointerDown = (event: MouseEvent) => {
            if (containerRef.current && ! containerRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);

        return () => document.removeEventListener('mousedown', onPointerDown);
    }, []);

    const choose = (customer: CustomerOption) => {
        onSelect(customer);
        setTerm('');
        setResults([]);
        setOpen(false);
    };

    if (selected) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-xl border border-cyan-400/30 bg-cyan-400/5 px-3 py-2.5">
                <span className="min-w-0 text-sm">
                    <span className="block truncate font-medium text-white">{selected.name}</span>
                    <span className="block truncate text-xs text-slate-400">
                        {selected.customer_number}{selected.company_name ? ` · ${selected.company_name}` : ''}
                    </span>
                </span>
                <button type="button" onClick={onClear} className="shrink-0 text-xs text-cyan-300 hover:text-cyan-200">
                    Change
                </button>
            </div>
        );
    }

    const showDropdown = open && term.trim().length >= MIN_TERM_LENGTH;

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (! showDropdown || results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => (index + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => (index - 1 + results.length) % results.length);
        } else if (event.key === 'Enter') {
            // The combobox lives inside the booking form; Enter picks a
            // suggestion here rather than submitting the shipment.
            event.preventDefault();
            choose(results[activeIndex]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <input
                id={inputId}
                type="text"
                role="combobox"
                aria-expanded={showDropdown}
                aria-controls={listboxId}
                aria-autocomplete="list"
                autoComplete="off"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
                placeholder={placeholder ?? 'Type a name, company or customer number…'}
                className={fieldClass}
            />

            {showDropdown && (
                <ul
                    id={listboxId}
                    role="listbox"
                    className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-white/10 bg-slate-900 py-1 shadow-xl"
                >
                    {loading && results.length === 0 && (
                        <li className="px-3 py-2 text-xs text-slate-500">Searching…</li>
                    )}
                    {! loading && results.length === 0 && (
                        <li className="px-3 py-2 text-xs text-slate-500">No customer matches “{term.trim()}”.</li>
                    )}
                    {results.map((customer, index) => (
                        <li key={customer.id} role="option" aria-selected={index === activeIndex}>
                            <button
                                type="button"
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => choose(customer)}
                                className={`block w-full px-3 py-2 text-left text-sm transition ${index === activeIndex ? 'bg-cyan-400/10 text-white' : 'text-slate-300'}`}
                            >
                                <span className="block truncate font-medium">{customer.name}</span>
                                <span className="block truncate text-xs text-slate-500">
                                    {customer.customer_number}
                                    {customer.company_name ? ` · ${customer.company_name}` : ''}
                                    {customer.addresses.length > 0 ? ` · ${customer.addresses.length} address${customer.addresses.length === 1 ? '' : 'es'}` : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
