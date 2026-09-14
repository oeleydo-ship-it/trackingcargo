<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAssignment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class MyDeliveriesController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $driver = request()->user()?->driver;

        if ($driver === null) {
            abort(403, 'Your account is not linked to a driver profile.');
        }

        return Inertia::render('Delivery/MyDeliveries', [
            'assignments' => DeliveryAssignment::query()
                ->where('driver_id', $driver->getKey())
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->with(['shipment:id,tracking_number,destination_city,destination_country_code', 'vehicle'])
                ->orderBy('scheduled_date')
                ->get(),
        ]);
    }
}
