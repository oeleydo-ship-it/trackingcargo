<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'status' => $this->resource->status->value,
            'is_platform_admin' => $this->resource->is_platform_admin,
            'company' => $this->resource->company === null ? null : [
                'id' => $this->resource->company->getKey(),
                'name' => $this->resource->company->name,
            ],
            'branch_id' => $this->resource->branch_id,
        ];
    }
}
