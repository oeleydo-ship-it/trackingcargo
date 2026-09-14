<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;

final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('billing.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->company_id !== $invoice->company_id || ! $user->hasPermission('billing.view')) {
            return false;
        }

        /**
         * A customer portal login (branch_id always null — see
         * User::customerProfile()) must never fall through to the
         * branch_id === null staff-bypass below, or they'd see every other
         * customer's invoices (and payment history) in the company.
         */
        if ($user->customerProfile !== null) {
            return $user->customerProfile->getKey() === $invoice->customer_id;
        }

        return $user->branch_id === null || $invoice->branch_id === null || $user->branch_id === $invoice->branch_id || $user->hasPermission('billing.manage');
    }

    /**
     * A not-yet-created invoice has no model to authorize against, so the
     * target customer is passed as a second argument, the same shape as
     * CustomsClearancePolicy::create()/DeliveryAssignmentPolicy::create().
     */
    public function create(User $user, ?Customer $customer = null): bool
    {
        if (! $user->hasPermission('billing.manage')) {
            return false;
        }

        if ($customer === null) {
            return true;
        }

        return $user->company_id === $customer->company_id
            && ($user->branch_id === null || $customer->branch_id === null || $user->branch_id === $customer->branch_id);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id && $user->hasPermission('billing.manage');
    }

    public function transition(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->company_id && $user->hasPermission('payments.manage');
    }
}
