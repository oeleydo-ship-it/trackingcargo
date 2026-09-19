<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\AddressType;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RateCard;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use App\Services\Platform\UserAccessService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CustomerService
{
    private const array AUDITABLE_FIELDS = ['branch_id', 'type', 'name', 'company_name', 'email', 'phone', 'tax_id', 'identification_number', 'credit_limit', 'status'];

    public function __construct(
        private TenantContext $tenantContext,
        private NumberSequenceService $sequences,
        private AuditService $audit,
        private UserAccessService $access,
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

    /**
     * Removes a customer from the list. The record is soft-deleted, so
     * shipments that were booked for them keep the names and addresses typed
     * on their own parties.
     *
     * Refused while the customer still owns invoices or a rate card of their
     * own: an invoice reached only through its customer would become
     * unreachable, and a customer-specific rate card would start reading as
     * "all customers". Setting the customer to inactive is the way to retire
     * one of those.
     *
     * A linked portal login is suspended along with the customer. Leaving it
     * active would be unsafe: a portal login is recognised by the customer it
     * belongs to, so once that customer is gone it would be treated as an
     * ordinary user with no branch and see the whole company's shipments.
     */
    public function delete(Customer $customer, User $actor): void
    {
        if ($customer->invoices()->exists()) {
            throw ValidationException::withMessages([
                'customer' => "{$customer->name} has invoices, which have to stay attached to a customer. Set the customer to inactive instead.",
            ]);
        }

        if (RateCard::query()->where('customer_id', $customer->getKey())->exists()) {
            throw ValidationException::withMessages([
                'customer' => "{$customer->name} has a rate card of their own. Remove or reassign it first, or set the customer to inactive instead.",
            ]);
        }

        DB::transaction(function () use ($customer, $actor): void {
            $portalUser = $customer->portalUser;

            if ($portalUser !== null) {
                // Done here rather than through UserInvitationService::suspend(),
                // which insists on users.manage: a customer manager deleting a
                // customer is not managing users, and this is not optional.
                $oldStatus = $portalUser->status;
                $portalUser->forceFill(['status' => UserStatus::Suspended])->save();
                $this->access->revoke($portalUser);
                $this->audit->record('user.suspended', $actor, $portalUser, oldValues: ['status' => $oldStatus->value], newValues: ['status' => UserStatus::Suspended->value]);

                $customer->forceFill(['portal_user_id' => null])->save();
            }

            $customer->delete();

            $this->audit->record('customer.deleted', $actor, $customer, oldValues: $customer->only(self::AUDITABLE_FIELDS));
        });
    }

    private function formatNumber(Company $company, int $sequence): string
    {
        return sprintf('%s-C-%06d', $company->code, $sequence);
    }
}
