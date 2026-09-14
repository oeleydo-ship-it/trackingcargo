<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

final class CompanyManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_a_user_with_permission_can_update_their_own_company(): void
    {
        $company = $this->createCompany('CMU');
        $actor = $this->createUser($company);
        $this->grantPermissions($actor, ['companies.view', 'companies.manage']);

        $this->actingAs($actor)
            ->patch('/settings/company', [
                'name' => 'Renamed Company',
                'timezone' => 'Asia/Dubai',
                'default_currency' => 'AED',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['id' => $company->getKey(), 'name' => 'Renamed Company']);
    }

    public function test_a_user_without_permission_cannot_update_the_company(): void
    {
        $company = $this->createCompany('CMN');
        $actor = $this->createUser($company);

        $this->actingAs($actor)
            ->patch('/settings/company', [
                'name' => 'Renamed Company',
                'timezone' => 'Asia/Dubai',
                'default_currency' => 'AED',
            ])
            ->assertForbidden();
    }
}
