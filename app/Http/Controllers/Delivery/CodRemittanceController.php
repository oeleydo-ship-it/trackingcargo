<?php

declare(strict_types=1);

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\StoreCodRemittanceRequest;
use App\Models\CodRemittance;
use App\Models\Driver;
use App\Services\Delivery\CodRemittanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CodRemittanceController extends Controller
{
    public function index(CodRemittanceService $remittances): Response
    {
        $this->authorize('viewAny', Driver::class);

        $outstanding = Driver::query()
            ->with('user:id,name')
            ->get()
            ->map(fn (Driver $driver): array => [
                'id' => $driver->getKey(),
                'name' => $driver->user->name,
                'outstanding' => $remittances->outstandingCod($driver),
            ])
            ->filter(fn (array $row): bool => $row['outstanding'] > 0)
            ->values();

        return Inertia::render('Delivery/CodRemittances/Index', [
            'outstanding' => $outstanding,
            'recentRemittances' => CodRemittance::query()
                ->with(['driver.user:id,name', 'actor:id,name'])
                ->orderByDesc('remitted_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(StoreCodRemittanceRequest $request, CodRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validated();
        $driver = Driver::query()->findOrFail($data['driver_id']);

        $remittances->remit($driver, (float) $data['amount'], $request->user(), $data['idempotency_key'], $data);

        return back()->with('success', 'COD remittance recorded.');
    }
}
