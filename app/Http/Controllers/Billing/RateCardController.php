<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreRateCardRequest;
use App\Models\RateCard;
use App\Services\Billing\RateCardService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class RateCardController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', RateCard::class);

        return Inertia::render('Billing/RateCards/Index', [
            'rateCards' => RateCard::query()
                ->with(['branch:id,name', 'customer:id,name', 'tiers'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(StoreRateCardRequest $request, RateCardService $rateCards): RedirectResponse
    {
        $rateCards->create($request->validated(), $request->user());

        return back()->with('success', 'Rate card created.');
    }
}
