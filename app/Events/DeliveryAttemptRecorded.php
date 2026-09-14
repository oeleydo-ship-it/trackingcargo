<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DeliveryAttempt;
use App\Services\Delivery\DeliveryAttemptPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class DeliveryAttemptRecorded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private readonly DeliveryAttempt $attempt) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->attempt->company_id}")];
    }

    public function broadcastAs(): string
    {
        return 'DeliveryAttemptRecorded';
    }

    public function broadcastWith(): array
    {
        return DeliveryAttemptPresenter::present($this->attempt);
    }
}
