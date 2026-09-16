import { useEffect, useState } from 'react';
import { applyTheme, preferredTheme, storeTheme, type Theme } from '../lib/theme';

interface ThemeToggleProps {
    /** Label beside the icon — the header is tight, the sign-in page is not. */
    labelled?: boolean;
}

/**
 * Switches the interface between the dark palette and a white background with
 * black text. The blade template applies the stored choice before first paint,
 * so this button only has to report and change it.
 */
export default function ThemeToggle({ labelled = false }: ThemeToggleProps) {
    const [theme, setTheme] = useState<Theme>('dark');

    useEffect(() => {
        setTheme(preferredTheme());
    }, []);

    const switchTo = (next: Theme) => {
        setTheme(next);
        applyTheme(next);
        storeTheme(next);
    };

    const next: Theme = theme === 'light' ? 'dark' : 'light';

    return (
        <button
            type="button"
            onClick={() => switchTo(next)}
            aria-label={`Switch to ${next} theme`}
            title={`Switch to ${next} theme`}
            className="flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white"
        >
            {/* The variation selector keeps the sun a glyph rather than a colour emoji. */}
            <span aria-hidden="true">{theme === 'light' ? '☾' : '☀︎'}</span>
            {labelled && <span>{next === 'light' ? 'Light' : 'Dark'}</span>}
        </button>
    );
}
