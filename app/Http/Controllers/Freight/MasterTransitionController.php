<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Enums\MasterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Freight\TransitionMasterRequest;
use App\Models\Master;
use App\Services\Freight\MasterTransitionService;
use Illuminate\Http\RedirectResponse;

final class MasterTransitionController extends Controller
{
    public function store(TransitionMasterRequest $request, Master $master, MasterTransitionService $transitions): RedirectResponse
    {
        $transitions->transition($master, MasterStatus::from($request->validated('status')), $request->user());

        return back()->with('success', 'Master status updated.');
    }
}
