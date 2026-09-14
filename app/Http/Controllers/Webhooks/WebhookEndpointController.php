<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\StoreWebhookEndpointRequest;
use App\Http\Requests\Webhooks\UpdateWebhookEndpointRequest;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class WebhookEndpointController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', WebhookEndpoint::class);

        return Inertia::render('Settings/Webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()
                ->with(['deliveries' => fn ($query) => $query->limit(10)])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(StoreWebhookEndpointRequest $request, AuditService $audit): RedirectResponse
    {
        $secret = Str::random(64);

        $endpoint = WebhookEndpoint::query()->create([
            ...$request->validated(),
            'secret' => $secret,
        ]);

        $audit->record('webhook-endpoint.created', $request->user(), $endpoint, newValues: ['url' => $endpoint->url]);

        return back()->with('success', "Endpoint created. Secret (shown once): {$secret}");
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhookEndpoint, AuditService $audit): RedirectResponse
    {
        $oldValues = $webhookEndpoint->only(array_keys($request->validated()));

        $webhookEndpoint->fill($request->validated())->save();

        $audit->record('webhook-endpoint.updated', $request->user(), $webhookEndpoint, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Endpoint updated.');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint, AuditService $audit): RedirectResponse
    {
        $this->authorize('delete', $webhookEndpoint);

        $webhookEndpoint->delete();

        $audit->record('webhook-endpoint.deleted', request()->user(), $webhookEndpoint);

        return back()->with('success', 'Endpoint removed.');
    }
}
