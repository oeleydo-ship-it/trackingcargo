/**
 * Light/dark appearance.
 *
 * The choice is per device rather than per account: it follows the screen the
 * user is looking at (a bright warehouse counter, a dim office) and it has to
 * work on the sign-in page, before anyone is authenticated. The theme itself
 * lives in CSS — adding `theme-light` to <html> re-points the colour tokens.
 */
export type Theme = 'dark' | 'light';

export const THEME_STORAGE_KEY = 'cargoflow.theme';

const THEME_COLORS: Record<Theme, string> = {
    dark: '#020617',
    light: '#ffffff',
};

export function storedTheme(): Theme | null {
    try {
        const stored = window.localStorage.getItem(THEME_STORAGE_KEY);

        return stored === 'dark' || stored === 'light' ? stored : null;
    } catch {
        // Private browsing and blocked site data both throw on access.
        return null;
    }
}

/**
 * Dark is the product's own look, so it stays the default: the light theme
 * appears only for someone who asked for it on this device.
 */
export function preferredTheme(): Theme {
    return storedTheme() ?? 'dark';
}

export function applyTheme(theme: Theme): void {
    document.documentElement.classList.toggle('theme-light', theme === 'light');
    document.documentElement.dataset.theme = theme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLORS[theme]);
}

export function storeTheme(theme: Theme): void {
    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch {
        // The theme still applies for this visit; it just will not be remembered.
    }
}
