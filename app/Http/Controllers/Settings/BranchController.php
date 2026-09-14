<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\StoreBranchRequest;
use App\Http\Requests\Identity\UpdateBranchRequest;
use App\Models\Branch;
use App\Services\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BranchController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Branch::class);

        return Inertia::render('Settings/Branches/Index', [
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreBranchRequest $request, AuditService $audit): RedirectResponse
    {
        $branch = Branch::query()->create($request->validated());

        $audit->record('branch.created', $request->user(), $branch, newValues: $request->validated());

        return back()->with('success', 'Branch created.');
    }

    public function update(UpdateBranchRequest $request, Branch $branch, AuditService $audit): RedirectResponse
    {
        $oldValues = $branch->only(array_keys($request->validated()));

        $branch->fill($request->validated())->save();

        $audit->record('branch.updated', $request->user(), $branch, oldValues: $oldValues, newValues: $request->validated());

        return back()->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch, AuditService $audit): RedirectResponse
    {
        $this->authorize('update', $branch);

        if ($branch->users()->exists()) {
            throw ValidationException::withMessages(['branch' => 'This branch has assigned users. Reassign them to an active branch before removing it.']);
        }

        $this->authorize('delete', $branch);

        $branch->delete();

        $audit->record('branch.deleted', request()->user(), $branch);

        return back()->with('success', 'Branch removed.');
    }
}
