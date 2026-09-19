<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Enums\BatchStatus;
use App\Models\ShipmentBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/** The search and filter query string on the batches list; every field is optional. */
final class BatchIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ShipmentBatch::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', new Enum(BatchStatus::class)],
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    /**
     * The filters with every key present, blank when not set.
     *
     * @return array{q: string, status: string, branch_id: string, from: string, to: string}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'status' => (string) ($validated['status'] ?? ''),
            'branch_id' => (string) ($validated['branch_id'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
    }
}
