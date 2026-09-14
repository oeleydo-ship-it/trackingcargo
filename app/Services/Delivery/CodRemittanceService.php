<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\CodRemittance;
use App\Models\Driver;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent by the same shape as PaymentService — same reasoning: a driver
 * or cashier's retried submission must not double-record cash handed in.
 * "Cannot over-apply" mirrors PaymentService's balance-due guard exactly,
 * but against outstanding *collected* COD rather than an invoice balance.
 */
final readonly class CodRemittanceService
{
    public function __construct(private AuditService $audit) {}

    public function remit(Driver $driver, float $amount, User $actor, string $idempotencyKey, array $data = []): CodRemittance
    {
        $existing = CodRemittance::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The remittance amount must be greater than zero.']);
        }

        $outstanding = $this->outstandingCod($driver);

        if (round($amount, 2) > round($outstanding, 2)) {
            throw ValidationException::withMessages(['amount' => sprintf('The remittance (%.2f) exceeds the outstanding COD owed (%.2f).', $amount, $outstanding)]);
        }

        try {
            $remittance = DB::transaction(function () use ($driver, $amount, $actor, $idempotencyKey, $data): CodRemittance {
                $remittance = $driver->codRemittances()->create([
                    'amount' => $amount,
                    'currency' => $data['currency'],
                    'remitted_at' => $data['remitted_at'] ?? now(),
                    'actor_id' => $actor->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $data['notes'] ?? null,
                ]);

                $this->audit->record('cod-remittance.recorded', $actor, $remittance, newValues: ['amount' => (string) $remittance->amount]);

                return $remittance;
            });
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() !== 23000) {
                throw $exception;
            }

            return CodRemittance::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        return $remittance;
    }

    /**
     * Live recompute — total collected across the driver's delivery attempts
     * minus total already remitted — never an independently-maintained
     * balance, same idiom as CustomerBalanceService.
     */
    public function outstandingCod(Driver $driver): float
    {
        $collected = (float) $driver->assignments()
            ->join('delivery_attempts', 'delivery_attempts.delivery_assignment_id', '=', 'delivery_assignments.id')
            ->sum('delivery_attempts.collected_amount');

        $remitted = (float) $driver->codRemittances()->sum('amount');

        return round($collected - $remitted, 2);
    }
}
