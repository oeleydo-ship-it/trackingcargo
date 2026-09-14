<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DriverLocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class DriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private readonly DriverLocation $location) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("company.{$this->location->company_id}")];
    }

    public function broadcastAs(): string
    {
        return 'DriverLocationUpdated';
    }

    public function broadcastWith(): array
    {
        $this->location->loadMissing('driver.user:id,name');

        return [
            'driver_id' => $this->location->driver_id,
            'driver_name' => $this->location->driver->user->name,
            'latitude' => (float) $this->location->latitude,
            'longitude' => (float) $this->location->longitude,
            'recorded_at' => $this->location->recorded_at->toIso8601String(),
        ];
    }
}
