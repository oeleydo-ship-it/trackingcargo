<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreBoxRequest;
use App\Http\Requests\Shipments\UpdateBoxRequest;
use App\Models\Box;
use App\Services\Shipments\BoxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BoxController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Box::class);

        return Inertia::render('Settings/Boxes/Index', [
            'boxes' => Box::query()->with('sizes')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreBoxRequest $request, BoxService $boxes): RedirectResponse
    {
        $boxes->create($request->validated(), $request->user());

        return back()->with('success', 'Box created.');
    }

    public function update(UpdateBoxRequest $request, Box $box, BoxService $boxes): RedirectResponse
    {
        $boxes->update($box, $request->validated(), $request->user());

        return back()->with('success', 'Box updated.');
    }

    public function setActive(Request $request, Box $box, BoxService $boxes): RedirectResponse
    {
        $this->authorize('update', $box);

        $boxes->setActive($box, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Box reactivated.' : 'Box deactivated.');
    }
}
