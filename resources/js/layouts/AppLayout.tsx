import { Link, router, usePage } from '@inertiajs/react';
import type { FormEvent, PropsWithChildren } from 'react';
import Brand from '../components/Brand';
import GlobalSearch from '../components/GlobalSearch';
import type { SharedPageProps } from '../types';

interface AppLayoutProps extends PropsWithChildren {
    title: string;
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth, flash } = usePage<SharedPageProps>().props;
    const { url: currentUrl } = usePage<SharedPageProps>();
    const user = auth.user;

    const navigation = [
        ['Dashboard', '⌂', '/dashboard'],
        ['Operations', '↗', '/shipments'],
        ['Batches', '⧉', '/batches'],
        ['Warehouse', '▦', '/warehouses'],
        ['Transportation', '⇄', '/freight/masters'],
        ['Customs', '◇', '/customs/queue'],
        user?.isDriver ? ['My Deliveries', '⌖', '/my-deliveries'] : ['Local Delivery', '⌖', '/deliveries/dispatch-board'],
        ['Customers', '◎', '/crm/customers'],
        ['Finance', '$', '/rate-cards'],
        ['Reports', '▥', '/reports/shipments'],
        ['Notifications', '◉', '/notifications'],
        ['My account', '◎', '/account'],
        ['Settings', '⚙', '/settings/company'],
        ...(user?.isPlatformAdmin ? [['Superadmin', '◆', '/superadmin']] : []),
    ] as const;

    const logout = (event: FormEvent) => {
        event.preventDefault();
        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 lg:grid lg:grid-cols-[17rem_1fr]">
            <aside className="border-b border-white/10 px-5 py-5 lg:min-h-screen lg:border-r lg:border-b-0">
                <div className="flex items-center justify-between">
                    <Link href="/dashboard"><Brand /></Link>
                    <span className="rounded-full border border-emerald-400/30 bg-emerald-400/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-300">Phase 10</span>
                </div>
                <nav className="mt-8 hidden space-y-1 text-sm lg:block" aria-label="Primary navigation">
                    {navigation.map(([label, icon, href]) => {
                        const active = href !== '#' && currentUrl.startsWith(href);

                        return (
                            <Link
                                key={label}
                                href={href}
                                className={`flex items-center gap-3 rounded-lg px-3 py-2.5 ${active ? 'bg-cyan-400 font-semibold text-slate-950' : 'text-slate-400 transition hover:bg-white/5 hover:text-white'}`}
                            >
                                <span className="w-5 text-center" aria-hidden="true">{icon}</span>{label}
                            </Link>
                        );
                    })}
                </nav>
            </aside>

            <div className="min-w-0">
                <header className="flex min-h-20 items-center justify-between border-b border-white/10 bg-slate-950/80 px-5 backdrop-blur md:px-8">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">{user?.company?.name ?? 'Platform administration'}</p>
                        <h1 className="mt-1 text-lg font-semibold">{title}</h1>
                    </div>
                    <div className="flex items-center gap-3">
                        <GlobalSearch />
                        <div className="hidden text-right sm:block">
                            <p className="text-sm font-medium">{user?.name}</p>
                            <p className="text-xs text-slate-500">{user?.email}</p>
                        </div>
                        <form onSubmit={logout}>
                            <button className="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 transition hover:border-cyan-400/50 hover:text-white">Sign out</button>
                        </form>
                    </div>
                </header>

                <main className="p-5 md:p-8">
                    {flash.success && <div className="mb-5 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{flash.success}</div>}
                    {flash.error && <div className="mb-5 rounded-xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{flash.error}</div>}
                    {children}
                </main>
            </div>
        </div>
    );
}
