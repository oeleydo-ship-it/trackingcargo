<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PackageScan;
use App\Services\Warehouse\PackageScanPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast synchronously (no queue worker required) rather than via ShouldBroadcast,
 * since this dev environment cannot reliably run Horizon (see docs/AGENT_HANDOFF.md).
 * Always dispatched after the owning DB transaction commits, never from inside it.
 */
final class PackageScanned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private readonly PackageScan $scan) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->scan->company_id}")];
    }

    public function broadcastAs(): string
    {
        return 'PackageScanned';
    }

    public function broadcastWith(): array
    {
        return PackageScanPresenter::present($this->scan);
    }
}
