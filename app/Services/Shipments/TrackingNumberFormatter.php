<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Renders a company's configurable tracking-number pattern.
 *
 * The pattern is stored on `companies.tracking_number_format` and is built from
 * the tokens in self::TOKENS. Everything outside a token is copied literally, so
 * `{company}-{branch}-{sequence}` yields `ACME-DXB-00000001`.
 */
final class TrackingNumberFormatter
{
    /**
     * Supported tokens, mapped to the help text shown in company settings.
     *
     * @var array<string, string>
     */
    public const array TOKENS = [
        '{company}' => 'Company code',
        '{branch}' => 'Branch tracking prefix',
        '{sequence}' => 'Zero-padded running number',
        '{year}' => 'Four-digit year (2026)',
        '{yy}' => 'Two-digit year (26)',
        '{month}' => 'Two-digit month (09)',
        '{day}' => 'Two-digit day (05)',
    ];

    public const string DEFAULT_FORMAT = '{company}-{branch}-{sequence}';

    public const string DEFAULT_BATCH_FORMAT = 'BATCH-{branch}-{sequence}';

    /**
     * A tracking number is a single path segment of the public tracking URL
     * (`/track/{trackingNumber}`) and is printed into Code128 barcodes, so it
     * may only hold characters that survive both. Notably that excludes `/`,
     * which would split the URL, and `{`/`}`, which Laravel's URL generator
     * mistakes for an unfilled route parameter.
     */
    public const string SAFE_NUMBER_PATTERN = '/^[A-Z0-9][A-Z0-9\-_.]*$/';

    public function isUrlSafe(string $trackingNumber): bool
    {
        return preg_match(self::SAFE_NUMBER_PATTERN, $trackingNumber) === 1;
    }

    /**
     * Explains why a pattern is unusable, or null when it is fine.
     *
     * Everything outside a token is copied into the tracking number verbatim,
     * so an unrecognised `{...}` group is not a token that will be substituted
     * — it lands in the number literally and breaks the tracking URL. That is
     * the mistake this catches: writing the company's own code as `{SGFS}`
     * instead of using the `{company}` token.
     */
    public function invalidFormatReason(string $format): ?string
    {
        preg_match_all('/\{[^{}]*\}/', $format, $matches);

        $unknown = array_values(array_diff(array_unique($matches[0]), array_keys(self::TOKENS)));

        if ($unknown !== []) {
            return 'Unknown token '.implode(', ', $unknown).'. Use the token buttons, or drop the braces to write the text literally.';
        }

        $literals = str_replace(array_keys(self::TOKENS), '', $format);

        if (preg_match('/^[A-Z0-9\-_.]*$/', $literals) !== 1) {
            return 'Text around the tokens may only use A-Z, 0-9, dashes, underscores and dots.';
        }

        if (! str_contains($format, '{sequence}')) {
            return 'The format must include the {sequence} token.';
        }

        if (preg_match('/^[A-Z0-9{]/', $format) !== 1) {
            return 'The format must start with a letter, a digit or a token.';
        }

        return null;
    }

    /**
     * The sequence scope implied by a format.
     *
     * A format that embeds the month restarts numbering each month, one that
     * embeds only the year restarts each year, and one with neither runs
     * continuously. Without this, `{yy}{sequence}` would keep counting across
     * the year boundary instead of restarting at 1.
     */
    public function period(string $format, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        if (str_contains($format, '{month}') || str_contains($format, '{day}')) {
            return $now->format('Y-m');
        }

        if (str_contains($format, '{year}') || str_contains($format, '{yy}')) {
            return $now->format('Y');
        }

        return 'ALL';
    }

    /**
     * Render a pattern. A null $sequence renders the sequence slot as `#`
     * placeholders, which is what the settings and booking previews show
     * without burning a real number.
     */
    public function render(string $format, Company $company, Branch $branch, ?int $sequence, int $padding, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();
        $padding = $this->clampPadding($padding);

        return strtr($format, [
            '{company}' => $company->code,
            '{branch}' => $branch->tracking_prefix,
            '{sequence}' => $sequence === null
                ? str_repeat('#', $padding)
                : str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT),
            '{year}' => $now->format('Y'),
            '{yy}' => $now->format('y'),
            '{month}' => $now->format('m'),
            '{day}' => $now->format('d'),
        ]);
    }

    public function format(Company $company): string
    {
        return $this->orDefault((string) $company->tracking_number_format, self::DEFAULT_FORMAT);
    }

    public function padding(Company $company): int
    {
        return $this->clampPadding((int) $company->tracking_sequence_padding);
    }

    public function batchFormat(Company $company): string
    {
        return $this->orDefault((string) $company->batch_number_format, self::DEFAULT_BATCH_FORMAT);
    }

    public function batchPadding(Company $company): int
    {
        return $this->clampPadding((int) $company->batch_sequence_padding);
    }

    private function orDefault(string $format, string $fallback): string
    {
        $format = trim($format);

        return $format === '' ? $fallback : $format;
    }

    private function clampPadding(int $padding): int
    {
        return max(1, min(12, $padding));
    }
}
