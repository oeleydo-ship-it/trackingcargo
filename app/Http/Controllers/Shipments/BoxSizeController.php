<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreBoxSizeRequest;
use App\Http\Requests\Shipments\UpdateBoxSizeRequest;
use App\Models\Box;
use App\Models\BoxSize;
use App\Services\Shipments\BoxSizeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BoxSizeController extends Controller
{
    public function store(StoreBoxSizeRequest $request, Box $box, BoxSizeService $sizes): RedirectResponse
    {
        $sizes->create($box, $request->validated(), $request->user());

        return back()->with('success', 'Size added.');
    }

    public function update(UpdateBoxSizeRequest $request, Box $box, BoxSize $size, BoxSizeService $sizes): RedirectResponse
    {
        abort_unless($size->box_id === $box->getKey(), 404);

        $sizes->update($size, $request->validated(), $request->user());

        return back()->with('success', 'Size updated.');
    }

    public function destroy(Request $request, Box $box, BoxSize $size, BoxSizeService $sizes): RedirectResponse
    {
        $this->authorize('update', $box);
        abort_unless($size->box_id === $box->getKey(), 404);

        $name = $size->name;

        try {
            $sizes->delete($size, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return back()->with('success', "Size \"{$name}\" deleted.");
    }

    public function setActive(Request $request, Box $box, BoxSize $size, BoxSizeService $sizes): RedirectResponse
    {
        $this->authorize('update', $box);
        abort_unless($size->box_id === $box->getKey(), 404);

        $sizes->setActive($size, $request->boolean('is_active'), $request->user());

        return back()->with('success', $request->boolean('is_active') ? 'Size reactivated.' : 'Size deactivated.');
    }
}
