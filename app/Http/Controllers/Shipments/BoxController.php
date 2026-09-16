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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BoxController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Box::class);

        return Inertia::render('Settings/Boxes/Index', [
            // The package count decides whether a size (and so its box) may be
            // deleted; see BoxService::delete(). Unscoped so packages on deleted
            // shipments count, matching BoxUsage.
            'boxes' => Box::query()
                ->with(['sizes' => fn ($query) => $query->withCount(['packages' => fn ($packages) => $packages->withoutGlobalScopes()])])
                ->orderBy('name')
                ->get(),
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

    public function installStandardSizes(Request $request, BoxService $boxes): RedirectResponse
    {
        $this->authorize('create', Box::class);

        ['box' => $box, 'added' => $added] = $boxes->installStandardSizes($request->user());

        return back()->with('success', $added === 0
            ? "{$box->name} already has all the standard sizes."
            : "Added {$added} standard size".($added === 1 ? '' : 's')." to {$box->name}.");
    }

    public function destroy(Box $box, BoxService $boxes): RedirectResponse
    {
        $this->authorize('update', $box);

        $name = $box->name;

        try {
            $boxes->delete($box, request()->user());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return back()->with('success', "Box \"{$name}\" deleted.");
    }

    public function setActive(Request $request, Box $box, BoxService $boxes): RedirectResponse
    {
        $this->authorize('update', $box);

        $boxes->setActive($box, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Box reactivated.' : 'Box deactivated.');
    }
}
