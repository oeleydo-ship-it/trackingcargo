<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Enums\ShipmentMode;
use App\Models\Shipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * The search and filter query string on the shipments list. Every field is
 * optional; the list simply narrows by whichever ones are present.
 */
final class ShipmentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Shipment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            // A status code is whatever the company's workflow generated from
            // the name they chose, so it can only be checked for shape here.
            'status' => ['nullable', 'string', 'max:60'],
            'mode' => ['nullable', new Enum(ShipmentMode::class)],
            'branch_id' => ['nullable', 'integer'],
            'carrier_id' => ['nullable', 'integer'],
            'country' => ['nullable', 'string', 'size:2'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    /**
     * The filters with every key present, blank when not set — the shape both
     * the query scope and the page's controls work from.
     *
     * @return array{q: string, status: string, mode: string, branch_id: string, carrier_id: string, country: string, from: string, to: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'mode' => (string) ($validated['mode'] ?? ''),
            'branch_id' => (string) ($validated['branch_id'] ?? ''),
            'carrier_id' => (string) ($validated['carrier_id'] ?? ''),
            'country' => strtoupper((string) ($validated['country'] ?? '')),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
    }
}
