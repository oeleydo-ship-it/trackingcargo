<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ShipmentStatusColors;
use App\Http\Requests\Settings\StoreShipmentStatusRequest;
use App\Http\Requests\Settings\UpdateShipmentStatusRequest;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Services\Shipments\ShipmentStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentStatusController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', ShipmentStatus::class);

        $statuses = ShipmentStatus::query()->ordered()->get();

        // How many shipments sit in each status, so the screen can warn before
        // someone retires one that is in use.
        $counts = Shipment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $edges = DB::table('shipment_status_transitions')
            ->where('company_id', $statuses->first()?->company_id)
            ->get(['from_status_id', 'to_status_id'])
            ->groupBy('from_status_id')
            ->map(fn ($group) => $group->pluck('to_status_id')->map(fn ($id): int => (int) $id)->values()->all());

        return Inertia::render('Settings/ShipmentStatuses/Index', [
            'statuses' => $statuses->map(fn (ShipmentStatus $status): array => [
                'id' => $status->getKey(),
                'code' => $status->code,
                'name' => $status->name,
                'color' => $status->color,
                'role' => $status->role?->value,
                'role_label' => $status->role?->label(),
                'system_use' => $status->role?->systemUse(),
                'sequence' => $status->sequence,
                'is_public' => $status->is_public,
                'is_terminal' => $status->is_terminal,
                'is_initial' => $status->is_initial,
                'is_active' => $status->is_active,
                'shipment_count' => (int) ($counts[$status->code] ?? 0),
                'transitions_to' => $edges->get($status->getKey(), []),
            ])->all(),
            'colors' => ShipmentStatusColors::ALL,
            'canManage' => request()->user()?->can('create', ShipmentStatus::class) ?? false,
        ]);
    }

    public function store(StoreShipmentStatusRequest $request, ShipmentStatusService $statuses): RedirectResponse
    {
        $statuses->create($request->validated(), $request->user());

        return back()->with('success', 'Status added.');
    }

    public function update(UpdateShipmentStatusRequest $request, ShipmentStatus $shipmentStatus, ShipmentStatusService $statuses): RedirectResponse
    {
        $statuses->update($shipmentStatus, $request->validated(), $request->user());

        return back()->with('success', 'Status updated.');
    }

    public function destroy(ShipmentStatus $shipmentStatus, ShipmentStatusService $statuses): RedirectResponse
    {
        $this->authorize('delete', $shipmentStatus);

        $statuses->delete($shipmentStatus, request()->user());

        return back()->with('success', 'Status removed.');
    }
}
