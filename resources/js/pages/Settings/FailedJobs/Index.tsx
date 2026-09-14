import { Head, router } from '@inertiajs/react';
import SettingsLayout from '../../../layouts/SettingsLayout';
import type { Paginated } from '../../../types';

interface FailedJob {
    id: number;
    uuid: string;
    connection: string;
    queue: string;
    job_name: string;
    exception_excerpt: string;
    failed_at: string;
}

interface FailedJobsIndexProps {
    jobs: Paginated<FailedJob>;
}

export default function FailedJobsIndex({ jobs }: FailedJobsIndexProps) {
    const retry = (uuid: string) => router.post(`/settings/failed-jobs/${uuid}/retry`);
    const remove = (uuid: string) => {
        if (confirm('Permanently remove this failed job? It cannot be retried afterward.')) {
            router.delete(`/settings/failed-jobs/${uuid}`);
        }
    };

    return (
        <SettingsLayout title="Failed jobs">
            <Head title="Failed jobs" />

            <p className="mb-5 text-sm text-slate-400">{jobs.total} failed job{jobs.total === 1 ? '' : 's'} across all companies (platform-wide — not company-scoped).</p>

            <div className="space-y-3">
                {jobs.data.map((job) => (
                    <article key={job.id} className="rounded-2xl border border-white/10 bg-white/[0.035] p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-mono text-sm">{job.job_name}</p>
                                <p className="mt-1 text-xs text-slate-500">{job.connection} · {job.queue} · {new Date(job.failed_at).toLocaleString()}</p>
                                <p className="mt-2 max-w-2xl truncate text-xs text-rose-300">{job.exception_excerpt}</p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <button onClick={() => retry(job.uuid)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-cyan-300 hover:border-cyan-400/50">Retry</button>
                                <button onClick={() => remove(job.uuid)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-rose-400 hover:border-rose-400/50">Remove</button>
                            </div>
                        </div>
                    </article>
                ))}
                {jobs.data.length === 0 && (
                    <p className="rounded-2xl border border-white/10 bg-white/[0.035] p-8 text-center text-sm text-slate-500">No failed jobs — everything is processing cleanly.</p>
                )}
            </div>
        </SettingsLayout>
    );
}
