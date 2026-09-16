<?php

declare(strict_types=1);

namespace App\Services\Numbering;

use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class NumberSequenceService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Atomically allocate the next number for a (company, branch, document type, period) sequence.
     *
     * Concurrency safety comes from `SELECT ... FOR UPDATE`: the row lock is acquired
     * immediately and held until the surrounding transaction commits, so two concurrent
     * callers allocating the same sequence serialize instead of racing. The very first
     * allocation for a scope has no row to lock yet, so it inserts one and falls back to
     * the lock-and-increment path if a concurrent request created that row first.
     */
    /**
     * $scopeSuffix separates counters that share a branch and document type —
     * one tracking-number format per counter, so sea and air each start at 1.
     * Left null, the scope key is exactly what it has always been, so existing
     * sequences carry on rather than restarting.
     */
    public function next(string $documentType, ?int $branchId = null, string $period = 'ALL', ?string $scopeSuffix = null): int
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $scopeKey = $branchId === null ? 'company' : "branch:{$branchId}";
        $scopeKey = $scopeSuffix === null ? $scopeKey : "{$scopeKey}|{$scopeSuffix}";

        return DB::transaction(function () use ($companyId, $branchId, $scopeKey, $documentType, $period): int {
            $criteria = [
                'company_id' => $companyId,
                'scope_key' => $scopeKey,
                'document_type' => $documentType,
                'period' => $period,
            ];

            $sequence = DB::table('number_sequences')->where($criteria)->lockForUpdate()->first();

            if ($sequence === null) {
                try {
                    DB::table('number_sequences')->insert([
                        ...$criteria,
                        'branch_id' => $branchId,
                        'next_number' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 1;
                } catch (QueryException $exception) {
                    if ((int) $exception->getCode() !== 23000) {
                        throw $exception;
                    }

                    $sequence = DB::table('number_sequences')->where($criteria)->lockForUpdate()->firstOrFail();
                }
            }

            $next = $sequence->next_number + 1;

            DB::table('number_sequences')->where('id', $sequence->id)->update([
                'next_number' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });
    }
}
