<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Enums\PlanInterval;

class PlanIntervalTest extends TestCase
{
    public function testValuesMatchTheOldPlanConstants(): void
    {
        $this->assertSame(Plan::INTERVAL_WEEK, PlanInterval::WEEK->value);
        $this->assertSame(Plan::INTERVAL_MONTH, PlanInterval::MONTH->value);
        $this->assertSame(Plan::INTERVAL_YEAR, PlanInterval::YEAR->value);
        $this->assertSame('day', PlanInterval::DAY->value);
        $this->assertSame(['day', 'week', 'month', 'year'], array_column(PlanInterval::cases(), 'value'));
    }
}
