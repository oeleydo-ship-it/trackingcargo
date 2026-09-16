import { Head, Link } from '@inertiajs/react';
import type { PropsWithChildren, ReactNode } from 'react';
import Brand from '../components/Brand';
import ThemeToggle from '../components/ThemeToggle';

interface AuthLayoutProps extends PropsWithChildren {
    title: string;
    eyebrow: string;
    heading: string;
    description: ReactNode;
}

export default function AuthLayout({ title, eyebrow, heading, description, children }: AuthLayoutProps) {
    return (
        <>
            <Head title={title} />
            <main className="grid min-h-screen bg-slate-950 text-slate-100 lg:grid-cols-[1.1fr_0.9fr]">
                <section className="relative hidden overflow-hidden border-r border-white/10 p-12 lg:flex lg:flex-col lg:justify-between">
                    <div className="absolute -left-32 top-1/4 size-96 rounded-full bg-cyan-400/10 blur-3xl" />
                    <Link href="/" className="relative"><Brand /></Link>
                    <div className="relative max-w-2xl">
                        <p className="mb-5 text-xs font-bold uppercase tracking-[0.3em] text-cyan-400">Freight intelligence, end to end</p>
                        <h1 className="text-5xl font-semibold leading-tight tracking-tight">One command center for every shipment movement.</h1>
                        <p className="mt-6 max-w-xl text-lg leading-8 text-slate-400">International freight, customs, warehouse scans, local delivery and proof of delivery—kept in one auditable timeline.</p>
                    </div>
                    <div className="relative grid grid-cols-3 gap-4 text-sm text-slate-400">
                        <span><strong className="block text-2xl text-white">24/7</strong>Operations visibility</span>
                        <span><strong className="block text-2xl text-white">Multi-mode</strong>Air, sea and road</span>
                        <span><strong className="block text-2xl text-white">Secure</strong>Tenant isolation</span>
                    </div>
                </section>
                <section className="relative flex items-center justify-center p-6 sm:p-12">
                    <div className="absolute right-6 top-6 sm:right-8 sm:top-8"><ThemeToggle labelled /></div>
                    <div className="w-full max-w-md">
                        <div className="mb-10 lg:hidden"><Brand compact /></div>
                        <p className="text-sm font-semibold text-cyan-400">{eyebrow}</p>
                        <h2 className="mt-2 text-3xl font-semibold tracking-tight">{heading}</h2>
                        <div className="mt-2 text-sm leading-6 text-slate-400">{description}</div>
                        {children}
                    </div>
                </section>
            </main>
        </>
    );
}
