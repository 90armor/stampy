<?php

namespace App\Exceptions;

use App\Enums\LeaveBalanceSource;
use InvalidArgumentException;

/**
 * A leave type whose own settings don't make sense — enforced on the model
 * (LeaveType::booted()), never a question of what references it (see
 * LeaveTypeLockedException/InUseException for that).
 *
 * - the balance source's fields (LeaveBalanceSource): a yearly type needs
 *   days_per_year; an earned or no-balance type has no yearly grant, so no
 *   days_per_year, seniority_bonus or min_service_months; a type with no
 *   balance can't carry over either (an earned one can).
 * - deducting from itself.
 * - a deduction chain: deducting from a type that itself deducts from
 *   another, or deducting at all while another type deducts from this one.
 *   One level only, so a request's balance always comes from one place.
 */
class InvalidLeaveTypeException extends InvalidArgumentException
{
    public static function yearlyWithoutDays(): self
    {
        return new self('A yearly balance needs days per year.');
    }

    public static function daysWithoutYearlyGrant(LeaveBalanceSource $source): self
    {
        return new self('Days per year are only for a yearly balance; '
            .($source === LeaveBalanceSource::Earned ? 'a balance earned from overtime' : 'a type with no balance').' has no yearly grant.');
    }

    public static function balanceOptionsWithoutBalance(): self
    {
        return new self('A type with no balance can\'t carry over or add a seniority bonus: those need a yearly balance (carry-over also works with an earned one).');
    }

    public static function yearlyOptionsWithoutYearlyGrant(): self
    {
        return new self('The seniority bonus and a service requirement need a yearly balance (days per year).');
    }

    public static function deductsFromItself(): self
    {
        return new self('A leave type can\'t deduct from itself.');
    }

    public static function deductionChain(): self
    {
        return new self('A leave type can deduct only from a type that doesn\'t deduct from another, and a type others deduct from can\'t deduct itself.');
    }
}
