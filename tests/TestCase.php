<?php

namespace Tests;

use App\Services\Setup\InstallationService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Most tests build tenants without ever creating a platform admin, which
     * on a real site would mean it is not set up yet and every page would
     * redirect to /setup. Tests of the first-run flow itself turn this off.
     */
    protected bool $siteInstalled = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->siteInstalled) {
            $this->app->make(InstallationService::class)->rememberInstalled();
        }
    }
}
