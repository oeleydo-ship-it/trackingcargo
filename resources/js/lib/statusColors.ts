/**
 * Statuses store a colour token, not CSS. The mapping lives here so a value
 * coming out of the database can never reach a class attribute directly, and
 * so every screen renders the same status the same way.
 */
export const STATUS_COLOR_TOKENS = ['slate', 'cyan', 'blue', 'indigo', 'violet', 'amber', 'emerald', 'rose'] as const;

export type StatusColor = (typeof STATUS_COLOR_TOKENS)[number];

const badgeClasses: Record<StatusColor, string> = {
    slate: 'text-slate-400 border-white/10',
    cyan: 'text-cyan-300 border-cyan-400/30',
    blue: 'text-blue-300 border-blue-400/30',
    indigo: 'text-indigo-300 border-indigo-400/30',
    violet: 'text-violet-300 border-violet-400/30',
    amber: 'text-amber-300 border-amber-400/30',
    emerald: 'text-emerald-300 border-emerald-400/30',
    rose: 'text-rose-300 border-rose-400/30',
};

const dotClasses: Record<StatusColor, string> = {
    slate: 'bg-slate-400',
    cyan: 'bg-cyan-400',
    blue: 'bg-blue-400',
    indigo: 'bg-indigo-400',
    violet: 'bg-violet-400',
    amber: 'bg-amber-400',
    emerald: 'bg-emerald-400',
    rose: 'bg-rose-400',
};

function isKnown(color: string): color is StatusColor {
    return color in badgeClasses;
}

export function statusBadgeClass(color: string | null | undefined): string {
    return color && isKnown(color) ? badgeClasses[color] : badgeClasses.slate;
}

export function statusDotClass(color: string | null | undefined): string {
    return color && isKnown(color) ? dotClasses[color] : dotClasses.slate;
}
