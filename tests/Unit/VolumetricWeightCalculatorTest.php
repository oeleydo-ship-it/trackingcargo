<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipments\VolumetricWeightCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VolumetricWeightCalculatorTest extends TestCase
{
    private VolumetricWeightCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new VolumetricWeightCalculator;
    }

    #[Test]
    public function it_normalizes_inches_to_centimeters(): void
    {
        self::assertSame(25.4, $this->calculator->normalizeDimensionToCm(10, 'in'));
        self::assertSame(50.0, $this->calculator->normalizeDimensionToCm(50, 'cm'));
    }

    #[Test]
    public function it_normalizes_pounds_to_kilograms(): void
    {
        self::assertEqualsWithDelta(4.536, $this->calculator->normalizeWeightToKg(10, 'lb'), 0.001);
        self::assertSame(5.0, $this->calculator->normalizeWeightToKg(5, 'kg'));
    }

    #[Test]
    public function it_rejects_an_unsupported_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->normalizeDimensionToCm(10, 'furlongs');
    }

    #[Test]
    public function it_computes_volumetric_weight_using_the_standard_air_divisor(): void
    {
        // A 50x40x30 cm box at the classic 6000 divisor is a textbook example: 10 kg.
        self::assertSame(10.0, $this->calculator->volumetricWeightKg(50, 40, 30, 6000));
    }

    #[Test]
    public function it_rejects_a_non_positive_divisor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->volumetricWeightKg(50, 40, 30, 0);
    }

    #[Test]
    public function chargeable_weight_is_the_greater_of_actual_and_volumetric(): void
    {
        self::assertSame(10.0, $this->calculator->chargeableWeightKg(4.0, 10.0));
        self::assertSame(15.0, $this->calculator->chargeableWeightKg(15.0, 10.0));
    }
}
