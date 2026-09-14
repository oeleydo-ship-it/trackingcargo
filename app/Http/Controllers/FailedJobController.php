<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `failed_jobs` has no company_id — it is a genuinely platform-wide system
 * table (a job can fail before or independent of any tenant context ever
 * being resolved), so this is gated to `failed-jobs.manage`
 * (platform_only in the permission catalog — effectively is_platform_admin
 * only, since User::hasPermission() never satisfies a platform_only
 * permission through a company-scoped role) rather than any company
 * permission, matching how Horizon's own dashboard is gated. Retry/forget
 * reuse Laravel's own `queue:retry`/`queue:forget` Artisan commands rather
 * than reimplementing the requeue logic.
 */
final class FailedJobController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless((bool) $request->user()?->hasPermission('failed-jobs.manage'), 403);

        $jobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->paginate(30)
            ->through(function (object $job): array {
                $payload = json_decode((string) $job->payload, true);

                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'job_name' => $payload['displayName'] ?? 'unknown',
                    'exception_excerpt' => Str::limit(explode("\n", (string) $job->exception)[0] ?? '', 300),
                    'failed_at' => $job->failed_at,
                ];
            });

        return Inertia::render('Settings/FailedJobs/Index', ['jobs' => $jobs]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('failed-jobs.manage'), 403);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Job re-queued.');
    }

    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('failed-jobs.manage'), 403);

        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('success', 'Failed job removed.');
    }
}
