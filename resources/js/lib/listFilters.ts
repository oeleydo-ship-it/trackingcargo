import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/** Only the filters actually in use, so the URL stays as short as the search is. */
export function activeFilters(filters: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}

export function hasActiveFilters(filters: Record<string, string>): boolean {
    return Object.keys(activeFilters(filters)).length > 0;
}

/**
 * The behaviour a list's search box and filters share.
 *
 * Nothing is sent while the fields are being filled in: the values are a draft
 * until `search()` runs — from the Search button or Enter in the search box —
 * so several filters can be set up and applied in one go. Each search is a
 * partial Inertia reload of just the list, replacing the history entry so Back
 * leaves the page instead of stepping through every search.
 *
 * `filters` is what the server is currently applying; `empty` is the same
 * shape with everything blank; `only` names the props worth reloading.
 */
export function useListFilters<T extends { q: string }>(path: string, filters: T, empty: T, only: string[]) {
    const [values, setValues] = useState<T>(filters);
    // What this hook last asked the server for, to tell its own round trips
    // apart from the URL changing under it (browser Back/Forward).
    const sent = useRef<T>(filters);

    const visit = (next: T) => {
        const normalised = { ...next, q: next.q.trim() };
        sent.current = normalised;

        router.get(path, activeFilters(normalised), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });
    };

    useEffect(() => {
        if (JSON.stringify(filters) !== JSON.stringify(sent.current)) {
            sent.current = filters;
            setValues(filters);
        }
    }, [filters]);

    return {
        /** The draft: what the fields currently show, applied or not. */
        values,
        /** Update the draft; nothing is requested until search(). */
        set: (patch: Partial<T>) => setValues((current) => ({ ...current, ...patch })),
        /** Apply the draft. */
        search: () => visit(values),
        /** Blank every field and apply that straight away. */
        clear: () => {
            setValues(empty);
            visit(empty);
        },
    };
}
