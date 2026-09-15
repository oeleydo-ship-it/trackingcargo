<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Enums\BatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreShipmentRequest;
use App\Http\Requests\Shipments\UpdateShipmentRequest;
use App\Models\Box;
use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentStatus;
use App\Services\Shipments\ShipmentService;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();

        return Inertia::render('Shipments/Index', [
            'shipments' => Shipment::query()
                ->with(['branch:id,name', 'customer:id,name', 'shipmentStatus:id,code,name,color', 'carrier:id,name,code'])
                ->when(
                    $user?->customerProfile !== null,
                    fn ($query) => $query->where('customer_id', $user->customerProfile->getKey()),
                    fn ($query) => $query->when(
                        $user?->branch_id !== null && ! $user->hasPermission('shipments.manage'),
                        fn ($query) => $query->where('branch_id', $user->branch_id),
                    ),
                )
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'boxes' => $this->activeBoxes(),
            'carriers' => $this->selectableCarriers(),
            'branches' => $this->bookableBranches($request),
            'trackingSettings' => $this->trackingSettings(),
            'openBatches' => $this->openBatches(),
        ]);
    }

    public function show(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->load(['shipmentStatus', 'carrier:id,name,code,website,contact_name,contact_email,contact_phone,is_active', 'branch:id,name', 'batch:id,batch_number,reference', 'customer:id,name', 'parties.addresses', 'parties.customer:id,name,customer_number', 'packages.boxSize.box:id,name', 'trackingEvents.createdBy:id,name', 'routeLegs', 'customsClearances', 'deliveryAssignments']);

        return Inertia::render('Shipments/Show', [
            'shipment' => $shipment,
            'allowedTransitions' => $this->allowedTransitions($shipment),
            // Every status, including retired ones, so the timeline can name a
            // status a shipment passed through months ago.
            'statuses' => app(ShipmentStatusRepository::class)->all((int) $shipment->company_id)
                ->mapWithKeys(fn (ShipmentStatus $status): array => [$status->code => ['name' => $status->name, 'color' => $status->color]])
                ->all(),
            'trackingUrl' => route('public.tracking.show', $shipment->tracking_number),
            'boxes' => $this->activeBoxes(),
            'carriers' => $this->selectableCarriers($shipment),
            'trackingSettings' => $this->trackingSettings(),
        ]);
    }

    /**
     * Where this shipment may go next, under its company's own transition
     * rules — the same rules ShipmentTransitionService enforces on submit.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    private function allowedTransitions(Shipment $shipment): array
    {
        $statuses = app(ShipmentStatusRepository::class);
        $from = $statuses->byCode((string) $shipment->status, (int) $shipment->company_id);

        if ($from === null) {
            return [];
        }

        return $statuses->allowedFrom($from)
            ->map(fn (ShipmentStatus $status): array => [
                'value' => $status->code,
                'label' => $status->name,
                'color' => $status->color,
            ])
            ->all();
    }

    /**
     * Branches the actor may book against. Mirrors BranchPolicy::view so a
     * branch-scoped clerk is not offered branches they cannot otherwise see.
     */
    private function bookableBranches(Request $request): Collection
    {
        $user = $request->user();

        return Branch::query()
            ->where('status', 'active')
            ->when(
                $user?->branch_id !== null && ! $user->hasPermission('branches.manage'),
                fn ($query) => $query->whereKey($user->branch_id),
            )
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'tracking_prefix']);
    }

    /**
     * Open batches the booking form can drop a new shipment into. Kept
     * unfiltered by branch so the form can narrow the list client-side as the
     * clerk picks a branch, without a round trip.
     */
    private function openBatches(): Collection
    {
        return ShipmentBatch::query()
            ->where('status', BatchStatus::Open)
            ->orderByDesc('id')
            ->get(['id', 'branch_id', 'batch_number', 'reference']);
    }

    /**
     * The company's tracking-number preferences, so the booking form can show
     * the same number the server is about to allocate and know whether a manual
     * override is on offer.
     *
     * @return array<string, mixed>
     */
    private function trackingSettings(): array
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId !== null ? Company::query()->find($companyId) : null;

        if ($company === null) {
            return ['companyCode' => '', 'format' => TrackingNumberFormatter::DEFAULT_FORMAT, 'padding' => 8, 'allowManual' => false];
        }

        $formatter = app(TrackingNumberFormatter::class);

        return [
            'companyCode' => $company->code,
            'format' => $formatter->format($company),
            'padding' => $formatter->padding($company),
            'allowManual' => (bool) $company->allow_manual_tracking_number,
        ];
    }

    /**
     * Carriers the booking and edit forms offer, from Settings → Carriers.
     *
     * Only active ones — except that a shipment already booked with a carrier
     * that has since been switched off keeps it in its own edit form, so
     * saving an unrelated correction does not silently drop the carrier.
     */
    private function selectableCarriers(?Shipment $shipment = null): Collection
    {
        return Carrier::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($shipment?->carrier_id !== null, fn ($query) => $query->orWhere('id', $shipment->carrier_id)))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'modes', 'is_active']);
    }

    private function activeBoxes(): Collection
    {
        return Box::query()
            ->where('is_active', true)
            ->with(['sizes' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function store(StoreShipmentRequest $request, ShipmentService $shipments): RedirectResponse
    {
        $shipment = $shipments->create(
            $request->safe()->except(['parties', 'packages']),
            $request->input('parties'),
            $request->input('packages'),
            $request->user(),
        );

        return to_route('shipments.show', $shipment)->with('success', "Shipment {$shipment->tracking_number} created.");
    }

    public function update(UpdateShipmentRequest $request, Shipment $shipment, ShipmentService $shipments): RedirectResponse
    {
        $shipments->update($shipment, $request->validated(), $request->user());

        return back()->with('success', 'Shipment updated.');
    }

    public function destroy(Request $request, Shipment $shipment, ShipmentService $shipments): RedirectResponse
    {
        $this->authorize('delete', $shipment);

        $shipments->delete($shipment, $request->user());

        return to_route('shipments.index')->with('success', "Shipment {$shipment->tracking_number} deleted.");
    }
}
