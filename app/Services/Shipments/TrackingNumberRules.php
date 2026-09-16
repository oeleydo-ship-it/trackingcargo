<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentMode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TrackingNumberFormat;
use Illuminate\Support\Collection;

/**
 * Picks the tracking-number format a shipment gets.
 *
 * A company has one default pattern (companies.tracking_number_format) and any
 * number of override rules. The most specific rule wins: branch + mode, then
 * branch, then mode, then the catch-all rule, then the company default.
 */
final class TrackingNumberRules
{
    /** @var array<int, Collection<int, TrackingNumberFormat>> */
    private array $rules = [];

    public function __construct(private readonly TrackingNumberFormatter $formatter) {}

    /**
     * The format, padding and sequence key for one booking.
     *
     * The sequence key is what keeps each format counting on its own. The
     * company default deliberately keeps the plain per-branch key it has always
     * used, so numbering carries on from where it is rather than restarting at
     * 1 and colliding with numbers already issued.
     *
     * @return array{format: string, padding: int, sequenceSuffix: ?string, rule: ?TrackingNumberFormat}
     */
    public function resolve(Company $company, Branch $branch, ?ShipmentMode $mode): array
    {
        $rule = $this->match($company, $branch, $mode);

        if ($rule === null) {
            return [
                'format' => $this->formatter->format($company),
                'padding' => $this->formatter->padding($company),
                'sequenceSuffix' => null,
                'rule' => null,
            ];
        }

        return [
            'format' => $rule->format,
            'padding' => $rule->sequence_padding,
            'sequenceSuffix' => 'fmt:'.$rule->getKey(),
            'rule' => $rule,
        ];
    }

    public function match(Company $company, Branch $branch, ?ShipmentMode $mode): ?TrackingNumberFormat
    {
        return $this->forCompany((int) $company->getKey())
            ->filter(fn (TrackingNumberFormat $rule): bool => ($rule->branch_id === null || $rule->branch_id === (int) $branch->getKey())
                && ($rule->mode === null || ($mode !== null && $rule->mode === $mode)))
            ->sortByDesc(fn (TrackingNumberFormat $rule): int => $rule->specificity())
            ->first();
    }

    /**
     * @return Collection<int, TrackingNumberFormat>
     */
    public function forCompany(int $companyId): Collection
    {
        return $this->rules[$companyId] ??= TrackingNumberFormat::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('branch:id,name')
            ->orderBy('scope')
            ->orderBy('mode_key')
            ->get();
    }

    public function forget(): void
    {
        $this->rules = [];
    }
}
