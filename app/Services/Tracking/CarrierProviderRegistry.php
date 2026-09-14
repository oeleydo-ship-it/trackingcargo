<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Contracts\CarrierProviderInterface;
use InvalidArgumentException;

/**
 * Resolves a named carrier integration by code, reading config/carriers.php
 * (code => class map) and instantiating through the container so each
 * provider can itself declare constructor dependencies (an HTTP client
 * config, API credentials, etc.) without this registry knowing about them.
 */
final class CarrierProviderRegistry
{
    /** @var array<string, CarrierProviderInterface> */
    private array $resolved = [];

    public function resolve(string $code): CarrierProviderInterface
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $class = config("carriers.providers.{$code}");

        if ($class === null) {
            throw new InvalidArgumentException("No carrier provider registered for code \"{$code}\".");
        }

        return $this->resolved[$code] = app($class);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(config('carriers.providers', []));
    }
}
