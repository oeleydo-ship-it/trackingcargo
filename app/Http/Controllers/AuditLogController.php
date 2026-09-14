<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $action = $request->string('action')->trim()->toString();

        return Inertia::render('Settings/AuditLog/Index', [
            'logs' => AuditLog::query()
                ->with('user:id,name')
                ->when($action !== '', fn ($query) => $query->where('action', 'like', '%'.addcslashes($action, '%_\\').'%'))
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'filters' => ['action' => $action],
        ]);
    }
}
