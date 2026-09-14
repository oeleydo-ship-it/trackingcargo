<?php

declare(strict_types=1);

namespace App\Http\Controllers\Freight;

use App\Http\Controllers\Controller;
use App\Models\Manifest;
use App\Models\Master;
use App\Services\Freight\ManifestPdfService;
use App\Services\Freight\ManifestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class ManifestController extends Controller
{
    public function store(Master $master, ManifestService $manifests): RedirectResponse
    {
        $this->authorize('create', Manifest::class);
        $this->authorize('view', $master);

        $manifest = $manifests->generate($master, request()->user());

        return back()->with('success', "Manifest {$manifest->manifest_number} generated.");
    }

    public function show(Master $master, Manifest $manifest, ManifestPdfService $pdf): Response
    {
        $this->authorize('view', $manifest);
        abort_unless($manifest->master_id === $master->getKey(), 404);

        return response($pdf->render($manifest), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$manifest->manifest_number}.pdf\"",
        ]);
    }
}
