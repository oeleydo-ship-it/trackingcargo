<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Enums\BatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\ShipmentIndexRequest;
use App\Http\Requests\Shipments\StoreShipmentRequest;
use App\Http\Requests\Shipments\UpdateShipmentRequest;
use App\Models\Box;
use App\Models\Branch;
use App\Models\Carrier;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentStatus;
use App\Models\TrackingNumberFormat;
use App\Models\User;
use App\Services\Shipments\ShipmentService;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\TrackingNumberFormatter;
use App\Services\Shipments\TrackingNumberRules;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentController extends Controller
{
    public function index(ShipmentIndexRequest $request): Response
    {
        $this->authorize('viewAny', Shipment::class);

        $user = $request->user();
        $filters = $request->filters();

        $shipments = $this->visibleShipments($user)
            ->with(['branch:id,name', 'customer:id,name', 'carrier:id,name,code'])
            ->filteredBy($filters)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Status names and colours come from each shipment's branch workflow.
        app(ShipmentStatusRepository::class)->attach($shipments->getCollection());

        return Inertia::render('Shipments/Index', [
            'shipments' => $shipments,
            'filters' => $filters,
            // Lazy: typing in the search box reloads only `shipments` and
            // `filters`, so these lookups run on the first load alone.
            'filterOptions' => fn (): array => $this->filterOptions($user),
            'boxes' => $this->activeBoxes(),
            'carriers' => $this->selectableCarriers(),
            'branches' => $this->bookableBranches($request),
            'trackingSettings' => $this->trackingSettings(),
            'openBatches' => $this->openBatches(),
        ]);
    }

    /**
     * The shipments this user may see at all, before any search or filter: a
     * customer portal login only their own, a branch-scoped clerk only their
     * branch's. Everything the list shows — rows and dropdown options alike —
     * starts from this, so a filter can never reveal what the list would not.
     *
     * @return Builder<Shipment>
     */
    private function visibleShipments(?User $user): Builder
    {
        return Shipment::query()->when(
            $user?->customerProfile !== null,
            fn (Builder $query) => $query->where('customer_id', $user->customerProfile->getKey()),
            fn (Builder $query) => $query->when(
                $user?->branch_id !== null && ! $user->hasPermission('shipments.manage'),
                fn (Builder $query) => $query->where('branch_id', $user->branch_id),
            ),
        );
    }

    /**
     * What the filter dropdowns offer: only values that occur on shipments
     * this user can see, so every choice returns something and nothing about
     * other branches or customers leaks through an option list.
     *
     * @return array{statuses: list<array{code: string, name: string, color: string}>, branches: Collection, carriers: Collection, countries: Collection}
     */
    private function filterOptions(?User $user): array
    {
        $visible = fn (): Builder => $this->visibleShipments($user);

        $usedCodes = $visible()->distinct()->pluck('status');

        // The same code can exist in the company workflow and in a branch's own
        // copy; the company's name for it wins, since that is what most
        // shipments use.
        $rows = ShipmentStatus::query()->whereIn('code', $usedCodes)->get()->sortBy('scope')->unique('code')->keyBy('code');

        $statuses = $usedCodes
            ->map(fn (string $code): array => [
                'code' => $code,
                'name' => $rows->get($code)?->name ?? Str::headline($code),
                'color' => $rows->get($code)?->color ?? 'slate',
                'sequence' => $rows->get($code)?->sequence ?? PHP_INT_MAX,
            ])
            ->sortBy([['sequence', 'asc'], ['name', 'asc']])
            ->map(fn (array $status): array => ['code' => $status['code'], 'name' => $status['name'], 'color' => $status['color']])
            ->values()
            ->all();

        return [
            'statuses' => $statuses,
            'branches' => Branch::query()
                ->whereIn('id', $visible()->whereNotNull('branch_id')->select('branch_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
            'carriers' => Carrier::query()
                ->whereIn('id', $visible()->whereNotNull('carrier_id')->select('carrier_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name']),
            'countries' => $visible()->whereNotNull('destination_country_code')->distinct()->orderBy('destination_country_code')->pluck('destination_country_code'),
        ];
    }

    public function show(Shipment $shipment): Response
    {
        $this->authorize('view', $shipment);

        $shipment->resolvedStatus();
        $shipment->load(['carrier:id,name,code,website,contact_name,contact_email,contact_phone,is_active', 'branch:id,name', 'batch:id,batch_number,reference', 'customer:id,name', 'parties.addresses', 'parties.customer:id,name,customer_number', 'packages.boxSize.box:id,name', 'trackingEvents.createdBy:id,name', 'routeLegs', 'customsClearances', 'deliveryAssignments']);

        return Inertia::render('Shipments/Show', [
            'shipment' => $shipment,
            'allowedTransitions' => $this->allowedTransitions($shipment),
            // Every status, including retired ones, so the timeline can name a
            // status a shipment passed through months ago.
            'statuses' => app(ShipmentStatusRepository::class)->forShipment($shipment)
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
        $from = $statuses->statusOf($shipment);

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
            return ['companyCode' => '', 'format' => TrackingNumberFormatter::DEFAULT_FORMAT, 'padding' => 8, 'allowManual' => false, 'rules' => []];
        }

        $formatter = app(TrackingNumberFormatter::class);

        return [
            'companyCode' => $company->code,
            'format' => $formatter->format($company),
            'padding' => $formatter->padding($company),
            'allowManual' => (bool) $company->allow_manual_tracking_number,
            // The branch/mode overrides, so the booking preview shows the same
            // number the server will allocate.
            'rules' => app(TrackingNumberRules::class)->forCompany((int) $company->getKey())
                ->map(fn (TrackingNumberFormat $rule): array => [
                    'branch_id' => $rule->branch_id,
                    'mode' => $rule->mode?->value,
                    'format' => $rule->format,
                    'padding' => $rule->sequence_padding,
                ])->all(),
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
