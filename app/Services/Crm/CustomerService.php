<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\AddressType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    private const array AUDITABLE_FIELDS = ['branch_id', 'type', 'name', 'company_name', 'email', 'phone', 'tax_id', 'identification_number', 'credit_limit', 'status'];

    public function __construct(
        private TenantContext $tenantContext,
        private NumberSequenceService $sequences,
        private AuditService $audit,
    ) {}

    public function create(array $data, ?User $actor = null): Customer
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $address = Arr::get($data, 'address');
        $data = Arr::except($data, ['address']);

        return DB::transaction(function () use ($data, $address, $actor, $companyId): Customer {
            $company = Company::query()->findOrFail($companyId);
            $data['customer_number'] = $this->formatNumber($company, $this->sequences->next('customer'));

            $customer = Customer::query()->create($data);

            $this->attachFirstAddress($customer, is_array($address) ? $address : null);

            $this->audit->record('customer.created', $actor, $customer, newValues: $customer->only([...self::AUDITABLE_FIELDS, 'customer_number']));

            return $customer;
        });
    }

    /**
     * Writes the address optionally captured on the create form.
     *
     * It is the customer's first, so it becomes the default for its type —
     * which is what makes it the one the booking form offers up when this
     * customer is later named as a consignor or consignee.
     *
     * @param  array<string, mixed>|null  $address
     */
    private function attachFirstAddress(Customer $customer, ?array $address): void
    {
        $fields = Arr::only($address ?? [], [
            'type', 'label', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'contact_name', 'contact_phone',
        ]);

        $fields = array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : $value, $fields),
            fn ($value): bool => $value !== null && $value !== '',
        );

        // A form left blank arrives as empty strings rather than as no address.
        if (($fields['line1'] ?? '') === '') {
            return;
        }

        $customer->addresses()->create([
            ...$fields,
            'type' => $fields['type'] ?? AddressType::Shipping->value,
            'country_code' => mb_strtoupper((string) $fields['country_code']),
            'is_default' => true,
        ]);
    }

    public function update(Customer $customer, array $data, ?User $actor = null): Customer
    {
        $oldValues = $customer->only(array_keys($data));

        $customer->fill($data)->save();

        $this->audit->record('customer.updated', $actor, $customer, oldValues: $oldValues, newValues: $data);

        return $customer;
    }

    private function formatNumber(Company $company, int $sequence): string
    {
        return sprintf('%s-C-%06d', $company->code, $sequence);
    }
}
