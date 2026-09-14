<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entries_cannot_be_updated(): void
    {
        $log = AuditLog::query()->create(['action' => 'test.created']);
        $log->action = 'test.tampered';

        $this->expectException(LogicException::class);
        $log->save();
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $log = AuditLog::query()->create(['action' => 'test.created']);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
