<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\MasterMode;
use App\Models\Master;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Master::class, $this->input('mode')]) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $isAir = $this->input('mode') === MasterMode::Air->value;

        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'mode' => ['required', new Enum(MasterMode::class)],
            'carrier_code' => [$isAir ? 'required' : 'prohibited', 'string', 'max:10'],
            'flight_number' => [$isAir ? 'required' : 'prohibited', 'string', 'max:10'],
            'origin_airport' => [$isAir ? 'required' : 'prohibited', 'string', 'size:3'],
            'destination_airport' => [$isAir ? 'required' : 'prohibited', 'string', 'size:3'],
            'shipping_line' => [$isAir ? 'prohibited' : 'required', 'string', 'max:255'],
            'vessel_name' => [$isAir ? 'prohibited' : 'required', 'string', 'max:255'],
            'voyage_number' => [$isAir ? 'prohibited' : 'required', 'string', 'max:20'],
            'origin_port' => [$isAir ? 'prohibited' : 'required', 'string', 'max:255'],
            'destination_port' => [$isAir ? 'prohibited' : 'required', 'string', 'max:255'],
            'scheduled_departure_at' => ['nullable', 'date'],
            'scheduled_arrival_at' => ['nullable', 'date', 'after:scheduled_departure_at'],
        ];
    }
}
