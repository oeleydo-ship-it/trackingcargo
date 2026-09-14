<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreRateCardTierRequest;
use App\Models\RateCard;
use App\Services\Billing\RateCardTierService;
use Illuminate\Http\RedirectResponse;

final class RateCardTierController extends Controller
{
    public function store(StoreRateCardTierRequest $request, RateCard $rateCard, RateCardTierService $tiers): RedirectResponse
    {
        $tiers->create($rateCard, $request->validated(), $request->user());

        return back()->with('success', 'Rate tier added.');
    }
}
