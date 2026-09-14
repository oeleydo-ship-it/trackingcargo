<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Invoice $invoice */
        $invoice = $this->route('invoice');

        return $this->user()?->can('transition', $invoice) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(InvoiceStatus::class)],
        ];
    }
}
