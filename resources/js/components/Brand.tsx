import { usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

interface BrandProps {
    compact?: boolean;
}

export default function Brand({ compact = false }: BrandProps) {
    const { branding } = usePage<SharedPageProps>().props;
    const siteName = branding?.siteName || 'CargoFlow';
    const initials = siteName
        .split(/\s+/)
        .map((word) => word[0])
        .join('')
        .slice(0, 2)
        .toUpperCase() || 'CF';

    return (
        <div className="flex items-center gap-3">
            {branding?.logoUrl ? (
                <img src={branding.logoUrl} alt={siteName} className="size-11 rounded-xl object-contain" />
            ) : (
                <span className="grid size-11 place-items-center rounded-xl bg-cyan-400 font-black text-slate-950 shadow-lg shadow-cyan-400/10">
                    {initials}
                </span>
            )}
            {!compact && (
                <span>
                    <strong className="block tracking-tight text-white">{siteName}</strong>
                    <small className="text-xs text-slate-500">Operations command</small>
                </span>
            )}
        </div>
    );
}
