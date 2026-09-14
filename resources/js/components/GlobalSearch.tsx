import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface SearchResult {
    id: number;
    label: string;
    sublabel: string | null;
    url: string;
}

interface SearchResponse {
    shipments: SearchResult[];
    customers: SearchResult[];
    invoices: SearchResult[];
}

const empty: SearchResponse = { shipments: [], customers: [], invoices: [] };
const groupLabels: Record<keyof SearchResponse, string> = { shipments: 'Shipments', customers: 'Customers', invoices: 'Invoices' };

export default function GlobalSearch() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResponse>(empty);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (query.trim().length < 2) {
            setResults(empty);
            return;
        }

        setLoading(true);
        const controller = new AbortController();
        const timeout = setTimeout(() => {
            fetch(`/search?q=${encodeURIComponent(query)}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((data: SearchResponse) => setResults(data))
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [query]);

    useEffect(() => {
        const onClickOutside = (event: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onClickOutside);
        return () => document.removeEventListener('mousedown', onClickOutside);
    }, []);

    const groups = (Object.keys(groupLabels) as (keyof SearchResponse)[]).filter((key) => results[key].length > 0);
    const hasResults = groups.length > 0;

    const go = (url: string) => {
        setOpen(false);
        setQuery('');
        router.visit(url);
    };

    return (
        <div ref={containerRef} className="relative hidden w-64 md:block">
            <input
                type="search"
                aria-label="Search tracking number, customer, or invoice"
                value={query}
                onChange={(event) => { setQuery(event.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                placeholder="Search tracking #, customer, invoice…"
                className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-4 focus:ring-cyan-400/10"
            />

            {open && query.trim().length >= 2 && (
                <div className="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-white/10 bg-slate-900 p-2 shadow-2xl shadow-black/40">
                    {loading && <p className="px-3 py-2 text-xs text-slate-500">Searching…</p>}
                    {!loading && !hasResults && <p className="px-3 py-2 text-xs text-slate-500">No matches.</p>}
                    {!loading && groups.map((key) => (
                        <div key={key} className="mb-1 last:mb-0">
                            <p className="px-3 pt-2 pb-1 text-[10px] font-semibold tracking-wider text-slate-500 uppercase">{groupLabels[key]}</p>
                            {results[key].map((result) => (
                                <button
                                    key={result.id}
                                    onClick={() => go(result.url)}
                                    className="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-200 hover:bg-white/5"
                                >
                                    <span className="font-mono">{result.label}</span>
                                    {result.sublabel && <span className="ml-2 text-xs text-slate-500">{result.sublabel}</span>}
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
