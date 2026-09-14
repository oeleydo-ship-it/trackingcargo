<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Enums\MasterStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Master;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class MasterService
{
    private const array FIELDS = [
        'branch_id', 'mode', 'carrier_code', 'flight_number', 'origin_airport', 'destination_airport',
        'shipping_line', 'vessel_name', 'voyage_number', 'origin_port', 'destination_port',
        'scheduled_departure_at', 'scheduled_arrival_at',
    ];

    public function __construct(
        private TenantContext $tenantContext,
        private NumberSequenceService $sequences,
        private AuditService $audit,
    ) {}

    public function create(array $data, User $actor): Master
    {
        $companyId = $this->tenantContext->requireCompanyId();

        return DB::transaction(function () use ($data, $actor, $companyId): Master {
            $company = Company::query()->findOrFail($companyId);
            $branch = Branch::query()->findOrFail($data['branch_id']);

            $master = Master::query()->create([
                ...Arr::only($data, self::FIELDS),
                'master_number' => $this->allocateNumber($company, $branch),
                'status' => MasterStatus::Open,
            ]);

            $this->audit->record('master.created', $actor, $master, newValues: [
                ...$master->only(self::FIELDS),
                'master_number' => $master->master_number,
            ]);

            return $master;
        });
    }

    private function allocateNumber(Company $company, Branch $branch): string
    {
        $sequence = $this->sequences->next('master', (int) $branch->getKey());

        return sprintf('%s-%s-M%06d', $company->code, $branch->tracking_prefix, $sequence);
    }
}
