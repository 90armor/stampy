<?php

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_under_an_hour_is_minutes_only(): void
    {
        $this->assertSame('0m', Duration::format(0));
        $this->assertSame('1m', Duration::format(1));
        $this->assertSame('21m', Duration::format(21));
        $this->assertSame('59m', Duration::format(59));
    }

    public function test_an_hour_or_more_is_hours_and_zero_padded_minutes(): void
    {
        $this->assertSame('1h 00m', Duration::format(60));
        $this->assertSame('1h 05m', Duration::format(65));
        $this->assertSame('1h 20m', Duration::format(80));
        $this->assertSame('8h 03m', Duration::format(483));
        $this->assertSame('152h 17m', Duration::format(9137));
    }
}
