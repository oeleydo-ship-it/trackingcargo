<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Address;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class AddressService
{
    public function create(Model $addressable, array $data): Address
    {
        return DB::transaction(function () use ($addressable, $data): Address {
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($addressable, $data['type']);
            }

            return $addressable->addresses()->create($data);
        });
    }

    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data): Address {
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($address->addressable, $data['type'] ?? $address->type->value, exceptId: $address->getKey());
            }

            $address->fill($data)->save();

            return $address;
        });
    }

    private function clearDefault(Model $addressable, string $type, ?int $exceptId = null): void
    {
        $addressable->addresses()
            ->where('type', $type)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }
}
