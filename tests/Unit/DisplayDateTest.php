<?php

namespace Tests\Unit;

use App\Support\DisplayDate;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DisplayDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_compact_is_weekday_day_month_with_the_year_only_outside_the_current_year(): void
    {
        $this->assertSame('Tue 29 Sep', DisplayDate::compact(Carbon::parse('2026-09-29')));
        $this->assertSame('Mon 20 May 2024', DisplayDate::compact(Carbon::parse('2024-05-20')));
    }

    public function test_range(): void
    {
        $this->assertSame('23–29 Sep', DisplayDate::range(Carbon::parse('2026-09-23'), Carbon::parse('2026-09-29')));
        $this->assertSame('28 Sep – 3 Oct', DisplayDate::range(Carbon::parse('2026-09-28'), Carbon::parse('2026-10-03')));
        $this->assertSame('Tue 29 Sep', DisplayDate::range(Carbon::parse('2026-09-29'), Carbon::parse('2026-09-29')));
        $this->assertSame('1–7 Mar 2025', DisplayDate::range(Carbon::parse('2025-03-01'), Carbon::parse('2025-03-07')));
        $this->assertSame('28 Dec 2025 – 3 Jan 2026', DisplayDate::range(Carbon::parse('2025-12-28'), Carbon::parse('2026-01-03')));
    }

    public function test_long_always_includes_the_year(): void
    {
        $this->assertSame('Tuesday, 29 September 2026', DisplayDate::long(Carbon::parse('2026-09-29')));
    }

    public function test_month_names_a_whole_month_and_always_includes_the_year(): void
    {
        $this->assertSame('October 2026', DisplayDate::month(Carbon::parse('2026-10-31')));
        $this->assertSame('January 2027', DisplayDate::month(Carbon::parse('2027-01-01')));
    }
}
