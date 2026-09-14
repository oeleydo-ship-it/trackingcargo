<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ImportCustomersRequest;
use App\Services\Crm\CustomerImportService;
use Illuminate\Http\RedirectResponse;

final class CustomerImportController extends Controller
{
    public function store(ImportCustomersRequest $request, CustomerImportService $importer): RedirectResponse
    {
        $result = $importer->import($request->file('file'), $request->user());

        $message = "{$result['created']} customer(s) imported.";

        if ($result['errors'] !== []) {
            $message .= ' '.count($result['errors']).' row(s) had errors and were skipped.';
        }

        return to_route('crm.customers.index')
            ->with($result['errors'] === [] ? 'success' : 'error', $message);
    }
}
