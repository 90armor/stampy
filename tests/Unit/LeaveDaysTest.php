<?php

namespace Tests\Unit;

use App\Support\LeaveDays;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Day amounts are integer tenths; the only rounding is up to the next half
 * day, done in integers, so its boundaries are exact.
 */
class LeaveDaysTest extends TestCase
{
    public function test_decimal_strings_convert_to_tenths_and_back_exactly(): void
    {
        $this->assertSame(180, LeaveDays::fromDecimal('18.0'));
        $this->assertSame(155, LeaveDays::fromDecimal('15.5'));
        $this->assertSame(-15, LeaveDays::fromDecimal('-1.5'));
        $this->assertSame(30, LeaveDays::fromDecimal('3'));
        $this->assertSame(30, LeaveDays::fromDecimal(3));
        $this->assertSame(0, LeaveDays::fromDecimal(null));
        $this->assertSame(5, LeaveDays::fromDecimal('0.50'));

        $this->assertSame('18.0', LeaveDays::toDecimal(180));
        $this->assertSame('-1.5', LeaveDays::toDecimal(-15));
        $this->assertSame('0.5', LeaveDays::toDecimal(5));
        $this->assertSame('15.5', LeaveDays::format(155));
        $this->assertSame('18', LeaveDays::format(180));
        $this->assertSame('-2', LeaveDays::format(-20));
    }

    public function test_an_amount_finer_than_a_tenth_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeaveDays::fromDecimal('1.25');
    }

    public function test_ceil_to_half_rounds_up_only_past_a_half_day_boundary(): void
    {
        // Exactly on a boundary stays put.
        $this->assertSame(155, LeaveDays::ceilToHalf(155 * 365, 365));
        $this->assertSame(180, LeaveDays::ceilToHalf(180 * 365, 365));
        $this->assertSame(0, LeaveDays::ceilToHalf(0, 365));
        // One unit past it rounds to the next half day.
        $this->assertSame(160, LeaveDays::ceilToHalf(155 * 365 + 1, 365));
        // 18 × 306 / 365 = 15.09 → 15.5 (the scope's 1 Mar example).
        $this->assertSame(155, LeaveDays::ceilToHalf(180 * 306, 365));
        // 18 × 61 / 365 = 3.008 → 3.5 (a 1 Nov joiner).
        $this->assertSame(35, LeaveDays::ceilToHalf(180 * 61, 365));
    }
}
