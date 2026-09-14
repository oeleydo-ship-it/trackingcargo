<?php

declare(strict_types=1);

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Type-ahead source for the booking form's consignor/consignee pickers.
 *
 * A JSON endpoint rather than an Inertia page: the booking form used to be
 * handed every customer up front, which stops being workable once a company
 * has more than a screenful of them. Clerks now type a few letters instead.
 *
 * Unlike the global SearchController this returns the details the form needs
 * to fill a party in — contact fields and the address book — so picking a
 * customer does not cost a second round trip.
 */
final class CustomerLookupController extends Controller
{
    /**
     * Below this many characters the term matches too much of the book to be
     * a useful suggestion, and the query is not worth running.
     */
    private const int MIN_TERM_LENGTH = 2;

    private const int LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        if ($user === null) {
            return response()->json(['customers' => []]);
        }

        // A portal login may only ever resolve to its own customer record, so
        // the picker cannot be turned into a way to enumerate the company's
        // customer book — the same rule ShipmentController::index() applies.
        $isPortalUser = $user->customerProfile !== null;

        if (! $isPortalUser && ! $user->hasPermission('customers.view') && ! $user->hasPermission('shipments.manage')) {
            return response()->json(['customers' => []]);
        }

        if (! $isPortalUser && mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return response()->json(['customers' => []]);
        }

        $customers = Customer::query()
            ->where('status', 'active')
            ->when($isPortalUser, fn ($query) => $query->whereKey($user->customerProfile->getKey()))
            ->when(! $isPortalUser, fn ($query) => $query->search($term))
            ->with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->orderBy('id')])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'company_name', 'email', 'phone', 'tax_id', 'customer_number']);

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer): array => [
                'id' => $customer->getKey(),
                'name' => $customer->name,
                'company_name' => $customer->company_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'tax_id' => $customer->tax_id,
                'customer_number' => $customer->customer_number,
                'addresses' => $customer->addresses->map(fn (Address $address): array => [
                    'id' => $address->getKey(),
                    'type' => $address->type->value,
                    'label' => $address->label,
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'postal_code' => $address->postal_code,
                    'country_code' => $address->country_code,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone,
                    'is_default' => $address->is_default,
                ])->all(),
            ])->all(),
        ]);
    }
}
