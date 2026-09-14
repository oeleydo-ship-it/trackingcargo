<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendWebhookJob;
use App\Models\WebhookDelivery;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WebhookRetryController extends Controller
{
    public function store(Request $request, WebhookDelivery $delivery, AuditService $audit)
    {
        $this->authorize('update', $delivery->endpoint);
        DB::transaction(function () use ($delivery, $request, $audit): void {
            $locked = WebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->status !== WebhookDeliveryStatus::Failed || ! $locked->endpoint?->is_active) {
                throw ValidationException::withMessages(['delivery' => 'Only a failed delivery to an active endpoint can be retried.']);
            }
            $locked->forceFill(['status' => WebhookDeliveryStatus::Pending])->save();
            $audit->record('webhook.delivery-retried', $request->user(), $locked);
            SendWebhookJob::dispatch((int) $locked->company_id, (int) $locked->id)->onQueue('webhooks')->afterCommit();
        });

        return back()->with('success', 'Webhook retry queued.');
    }
}
