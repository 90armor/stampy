<?php

namespace Database\Seeders;

use App\Enums\LeaveBalanceSource;
use App\Enums\LeaveCounting;
use App\Models\LeaveType;
use App\Models\OvertimeSettings;
use Illuminate\Database\Seeder;

/**
 * The company's leave types — real configuration, not demo data (unlike
 * HolidaySeeder's relative-date slots). Annual, Medical and Unpaid are
 * company policy; Special and Maternity follow the Cambodian Labour Law
 * (CLAUDE.md, Phase 3 scope, Policy rule 2). HR has yet to confirm the
 * final list — a change there is a change to this file only.
 *
 * Idempotent: updateOrCreate by name, so running it again updates the types
 * in place rather than duplicating them. No WithoutModelEvents: LeaveType's
 * own rules (LeaveType::booted()) must run on these rows too.
 *
 * Also points the overtime settings at "Time off in lieu" (Phase 4) — only
 * while nothing is set, so it never overwrites an admin's choice.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $annual = LeaveType::updateOrCreate(['name' => 'Annual'], [
            'balance_source' => LeaveBalanceSource::Yearly,
            'days_per_year' => 18,
            // Usable only after 12 months of service (Labour Law).
            'min_service_months' => 12,
            // +1 day per 3 completed years of service.
            'seniority_bonus' => true,
            'carry_over_cap' => 6,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => null,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => true,
            'is_paid' => true,
        ]);

        LeaveType::updateOrCreate(['name' => 'Medical'], [
            'balance_source' => LeaveBalanceSource::Yearly,
            'days_per_year' => 30,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => null,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => null,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => true,
            'is_paid' => true,
        ]);

        // Family events (marriage, birth, illness or death of a spouse, child
        // or parent): up to 7 days a request, taken from the Annual balance.
        LeaveType::updateOrCreate(['name' => 'Special'], [
            'balance_source' => LeaveBalanceSource::None,
            'days_per_year' => null,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => null,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => 7,
            'deducts_from_leave_type_id' => $annual->id,
            'allows_half_day' => true,
            'is_paid' => true,
        ]);

        // 90 calendar days a request, weekends and holidays included, by law;
        // paid at 50%, which is payroll's concern, not this app's.
        LeaveType::updateOrCreate(['name' => 'Maternity'], [
            'balance_source' => LeaveBalanceSource::None,
            'days_per_year' => null,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => null,
            'counts' => LeaveCounting::CalendarDays,
            'max_days_per_request' => 90,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => false,
            'is_paid' => true,
        ]);

        LeaveType::updateOrCreate(['name' => 'Unpaid'], [
            'balance_source' => LeaveBalanceSource::None,
            'days_per_year' => null,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => null,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => null,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => true,
            'is_paid' => false,
        ]);

        // Time off in lieu (Phase 4): no yearly grant — the balance is what
        // overtime taken as time off earns (TOIL settlement posts it as
        // adjustments), taken like Annual. The carry-over cap of 5 days is a
        // placeholder until HR decides it (CLAUDE.md, Phase 4, "Still open
        // (HR)"): not null, because null means no carry-over, and a half day
        // earned on 30 Dec would lapse the next day — time already worked.
        $toil = LeaveType::updateOrCreate(['name' => 'Time off in lieu'], [
            'balance_source' => LeaveBalanceSource::Earned,
            'days_per_year' => null,
            'min_service_months' => null,
            'seniority_bonus' => false,
            'carry_over_cap' => 5,
            'counts' => LeaveCounting::Workdays,
            'max_days_per_request' => null,
            'deducts_from_leave_type_id' => null,
            'allows_half_day' => true,
            'is_paid' => true,
        ]);

        $settings = OvertimeSettings::current();

        if ($settings->toil_leave_type_id === null) {
            $settings->update(['toil_leave_type_id' => $toil->id]);
        }
    }
}
