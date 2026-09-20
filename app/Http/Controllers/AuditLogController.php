<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\AuditTrackingNumbers;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $action = $request->string('action')->trim()->toString();

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($action !== '', fn ($query) => $query->where('action', 'like', '%'.addcslashes($action, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $trackingNumbers = AuditTrackingNumbers::for($logs->getCollection());

        $logs->getCollection()->each(fn (AuditLog $log) => $log->setAttribute('tracking_numbers', $trackingNumbers[$log->getKey()] ?? []));

        return Inertia::render('Settings/AuditLog/Index', [
            'logs' => $logs,
            'filters' => ['action' => $action],
        ]);
    }
}
