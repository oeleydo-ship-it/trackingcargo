<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Enums\DeliveryAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryAttempt;
use App\Models\Driver;
use App\Services\Delivery\DeliveryAttemptPresenter;
use Inertia\Inertia;
use Inertia\Response;

final class DispatchBoardController extends Controller
{
    public function show(): Response
    {
        $this->authorize('viewAny', DeliveryAssignment::class);

        return Inertia::render('Delivery/DispatchBoard', [
            'activeAssignments' => DeliveryAssignment::query()
                ->whereIn('status', [DeliveryAssignmentStatus::Assigned->value, DeliveryAssignmentStatus::OutForDelivery->value])
                ->with(['shipment:id,tracking_number,destination_city', 'driver.user:id,name', 'driver.location'])
                ->orderByDesc('id')
                ->get(),
            'recentAttempts' => DeliveryAttempt::query()
                ->with(['assignment.shipment:id,tracking_number', 'assignment.driver.user:id,name'])
                ->orderByDesc('attempted_at')
                ->limit(50)
                ->get()
                ->map(DeliveryAttemptPresenter::present(...))
                ->values(),
            'driverLocations' => Driver::query()
                ->whereHas('location')
                ->with(['user:id,name', 'location'])
                ->get()
                ->map(fn (Driver $driver) => [
                    'driver_id' => $driver->getKey(),
                    'driver_name' => $driver->user->name,
                    'latitude' => (float) $driver->location->latitude,
                    'longitude' => (float) $driver->location->longitude,
                    'recorded_at' => $driver->location->recorded_at->toIso8601String(),
                ])
                ->values(),
        ]);
    }
}
