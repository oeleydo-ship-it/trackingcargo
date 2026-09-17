<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\PublicFieldVisibility;
use App\Models\Address;
use App\Models\Company;
use App\Models\ShipmentParty;

/**
 * Decides how much of a shipment's sender and receiver the public tracking page
 * shows, and renders each field at that level.
 *
 * The public page is unauthenticated: anyone holding a tracking number sees
 * whatever this allows, so every field defaults to the least it can be and a
 * company opts in to more under Settings -> Public tracking.
 */
final class PublicTrackingFieldPolicy
{
    public const string SENDER = 'sender';

    public const string RECEIVER = 'receiver';

    /** The fields a company can set a level for, and how they read in settings. */
    public const array FIELDS = [
        'name' => 'Name',
        'address' => 'Address',
        'phone' => 'Phone',
        'email' => 'Email',
    ];

    /**
     * Shipped defaults: both parties identifiable with a full address so a
     * recipient can check where a parcel is going, the receiver's phone shown
     * for the courier's benefit, and the sender's phone and both email
     * addresses withheld.
     *
     * @var array<string, array<string, string>>
     */
    public const array DEFAULTS = [
        self::SENDER => ['name' => 'full', 'address' => 'full', 'phone' => 'hidden', 'email' => 'hidden'],
        self::RECEIVER => ['name' => 'full', 'address' => 'full', 'phone' => 'full', 'email' => 'hidden'],
    ];

    /**
     * The company's configured matrix, with any missing or unrecognised entry
     * falling back to the default for that field.
     *
     * @return array<string, array<string, PublicFieldVisibility>>
     */
    public function settings(?Company $company): array
    {
        $stored = is_array($company?->public_tracking_parties) ? $company->public_tracking_parties : [];
        $resolved = [];

        foreach (self::DEFAULTS as $role => $fields) {
            foreach ($fields as $field => $default) {
                $resolved[$role][$field] = PublicFieldVisibility::tryFrom((string) ($stored[$role][$field] ?? ''))
                    ?? PublicFieldVisibility::from($default);
            }
        }

        return $resolved;
    }

    /**
     * The party as the public page may show it, or null when the company has
     * hidden every field of it.
     *
     * company_name follows the name setting rather than having one of its own:
     * a trading name is the same kind of identification as a personal name, and
     * showing one while masking the other would defeat the masking.
     *
     * @param  array<string, PublicFieldVisibility>  $levels
     * @return array<string, mixed>|null
     */
    public function present(?ShipmentParty $party, array $levels): ?array
    {
        if ($party === null) {
            return null;
        }

        $address = $party->addresses->first();
        $nameLevel = $levels['name'];

        $presented = [
            'name' => $this->text($party->name, $nameLevel, fn (string $value): string => $this->maskName($value)),
            'company_name' => $this->text($party->company_name, $nameLevel, fn (string $value): string => $this->maskName($value)),
            'phone' => $this->text($party->phone, $levels['phone'], fn (string $value): string => $this->maskPhone($value)),
            'email' => $this->text($party->email, $levels['email'], fn (string $value): string => $this->maskEmail($value)),
            ...$this->addressLines($address, $levels['address']),
        ];

        $shown = array_filter($presented, static fn ($value): bool => $value !== null && $value !== []);

        return $shown === [] ? null : $presented;
    }

    /**
     * City and country stay in their own keys at every level so the page can
     * show "Dubai, AE" on one line whether or not the street is included.
     *
     * @return array{address_lines: list<string>, city: ?string, state: ?string, postal_code: ?string, country_code: ?string}
     */
    private function addressLines(?Address $address, PublicFieldVisibility $level): array
    {
        $empty = ['address_lines' => [], 'city' => null, 'state' => null, 'postal_code' => null, 'country_code' => null];

        if ($address === null || $level === PublicFieldVisibility::Hidden) {
            return $empty;
        }

        // Partial is the long-standing behaviour of this page: roughly where,
        // never the doorstep.
        if ($level === PublicFieldVisibility::Masked) {
            return [...$empty, 'city' => $address->city, 'country_code' => $address->country_code];
        }

        return [
            'address_lines' => array_values(array_filter([$address->line1, $address->line2], static fn (?string $line): bool => $line !== null && trim($line) !== '')),
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country_code' => $address->country_code,
        ];
    }

    /** @param callable(string): string $mask */
    private function text(?string $value, PublicFieldVisibility $level, callable $mask): ?string
    {
        if ($value === null || trim($value) === '' || $level === PublicFieldVisibility::Hidden) {
            return null;
        }

        return $level === PublicFieldVisibility::Full ? $value : $mask($value);
    }

    /**
     * "John Smith" -> "John S."; a single-word name has its middle characters
     * starred out instead, since there is no surname to drop.
     */
    private function maskName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = array_shift($words);

        if ($words === []) {
            $length = mb_strlen($first);

            return $length <= 2 ? $first : mb_substr($first, 0, 1).str_repeat('*', $length - 2).mb_substr($first, -1);
        }

        return $first.' '.implode(' ', array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)).'.',
            $words,
        ));
    }

    /** Keeps the last four digits, enough for the recipient to recognise their own number. */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (mb_strlen($digits) <= 4) {
            return str_repeat('•', mb_strlen($digits));
        }

        return str_repeat('•', mb_strlen($digits) - 4).mb_substr($digits, -4);
    }

    /** "jenny@example.com" -> "j•••y@example.com"; the domain is not the identifying part. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, null);

        if ($domain === null || $local === '') {
            return str_repeat('•', mb_strlen($email));
        }

        $length = mb_strlen($local);
        $masked = $length <= 2
            ? str_repeat('•', $length)
            : mb_substr($local, 0, 1).str_repeat('•', min($length - 2, 3)).mb_substr($local, -1);

        return $masked.'@'.$domain;
    }
}
