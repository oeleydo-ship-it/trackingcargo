<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePaymentRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\PaymentService;
use Illuminate\Http\RedirectResponse;

final class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, Customer $customer, Invoice $invoice, PaymentService $payments): RedirectResponse
    {
        abort_unless($invoice->customer_id === $customer->getKey(), 404);

        $data = $request->validated();

        $payments->record(
            $invoice,
            (float) $data['amount'],
            PaymentMethod::from($data['method']),
            $request->user(),
            $data['idempotency_key'],
            $data,
        );

        return back()->with('success', 'Payment recorded.');
    }
}
