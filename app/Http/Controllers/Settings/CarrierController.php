<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCarrierRequest;
use App\Http\Requests\Settings\UpdateCarrierRequest;
use App\Models\Carrier;
use App\Services\Shipments\CarrierService;
use App\Services\Tracking\CarrierProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CarrierController extends Controller
{
    public function index(Request $request, CarrierProviderRegistry $integrations): Response
    {
        $this->authorize('viewAny', Carrier::class);

        return Inertia::render('Settings/Carriers/Index', [
            'carriers' => Carrier::query()
                ->withCount('shipments')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'integrations' => $integrations->codes(),
            'canManage' => $request->user()?->can('create', Carrier::class) ?? false,
        ]);
    }

    public function store(StoreCarrierRequest $request, CarrierService $carriers): RedirectResponse
    {
        $carriers->create($request->validated(), $request->user());

        return back()->with('success', 'Carrier added.');
    }

    public function update(UpdateCarrierRequest $request, Carrier $carrier, CarrierService $carriers): RedirectResponse
    {
        $carriers->update($carrier, $request->validated(), $request->user());

        return back()->with('success', 'Carrier updated.');
    }

    public function setActive(Request $request, Carrier $carrier, CarrierService $carriers): RedirectResponse
    {
        $this->authorize('update', $carrier);

        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $carriers->setActive($carrier, (bool) $active, $request->user());

        return back()->with('success', $active ? 'Carrier switched on.' : 'Carrier switched off.');
    }
}
