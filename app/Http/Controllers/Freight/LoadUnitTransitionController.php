<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Enums\LoadUnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\TransitionLoadUnitRequest;
use App\Models\LoadUnit;
use App\Models\Master;
use App\Services\Freight\LoadUnitTransitionService;
use Illuminate\Http\RedirectResponse;

final class LoadUnitTransitionController extends Controller
{
    public function store(TransitionLoadUnitRequest $request, Master $master, LoadUnit $unit, LoadUnitTransitionService $transitions): RedirectResponse
    {
        abort_unless($unit->master_id === $master->getKey(), 404);

        $transitions->transition($unit, LoadUnitStatus::from($request->validated('status')), $request->user());

        return back()->with('success', 'Load unit status updated.');
    }
}
