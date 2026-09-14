<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditService
{
    public function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'company_id' => $actor?->company_id ?? $subject?->getAttribute('company_id'),
            'branch_id' => $actor?->branch_id ?? $subject?->getAttribute('branch_id'),
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->headers->get('X-Request-ID') ?? (string) Str::uuid(),
        ]);
    }

    private function sanitize(array $values): array
    {
        return collect($values)->except([
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
        ])->all();
    }
}
