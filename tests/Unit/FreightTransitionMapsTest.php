<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LoadUnitStatus;
use App\Enums\MasterStatus;
use App\Enums\RouteLegStatus;
use App\Services\Freight\LoadUnitTransitionMap;
use App\Services\Freight\MasterTransitionMap;
use App\Services\Freight\RouteLegTransitionMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FreightTransitionMapsTest extends TestCase
{
    #[Test]
    public function a_master_cannot_depart_before_it_is_closed(): void
    {
        self::assertFalse(MasterTransitionMap::isAllowed(MasterStatus::Open, MasterStatus::Departed));
        self::assertTrue(MasterTransitionMap::isAllowed(MasterStatus::Closed, MasterStatus::Departed));
    }

    #[Test]
    public function a_closed_master_can_be_reopened_for_loading(): void
    {
        self::assertTrue(MasterTransitionMap::isAllowed(MasterStatus::Closed, MasterStatus::Open));
    }

    #[Test]
    public function a_departed_or_closed_out_master_is_a_dead_end(): void
    {
        self::assertSame([], MasterTransitionMap::allowedFrom(MasterStatus::ClosedOut));
        self::assertFalse(MasterTransitionMap::isAllowed(MasterStatus::Departed, MasterStatus::Open));
    }

    #[Test]
    public function a_route_leg_cannot_skip_straight_to_arrived(): void
    {
        self::assertFalse(RouteLegTransitionMap::isAllowed(RouteLegStatus::Planned, RouteLegStatus::Arrived));
        self::assertTrue(RouteLegTransitionMap::isAllowed(RouteLegStatus::Planned, RouteLegStatus::Loaded));
    }

    #[Test]
    public function a_load_unit_cannot_go_directly_from_building_to_in_transit(): void
    {
        self::assertFalse(LoadUnitTransitionMap::isAllowed(LoadUnitStatus::Building, LoadUnitStatus::InTransit));
        self::assertTrue(LoadUnitTransitionMap::isAllowed(LoadUnitStatus::Building, LoadUnitStatus::Loaded));
    }

    #[Test]
    public function an_unloaded_load_unit_is_terminal(): void
    {
        self::assertSame([], LoadUnitTransitionMap::allowedFrom(LoadUnitStatus::Unloaded));
    }
}
