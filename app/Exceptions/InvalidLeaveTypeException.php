<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * A leave type whose own settings don't make sense — enforced on the model
 * (LeaveType::booted()), never a question of what references it (see
 * LeaveTypeLockedException/InUseException for that).
 *
 * - carry_over_cap or seniority_bonus on a type with no balance
 *   (days_per_year null): there is nothing to carry over or add to.
 * - deducting from itself.
 * - a deduction chain: deducting from a type that itself deducts from
 *   another, or deducting at all while another type deducts from this one.
 *   One level only, so a request's balance always comes from one place.
 */
class InvalidLeaveTypeException extends InvalidArgumentException
{
    public static function balanceOptionsWithoutBalance(): self
    {
        return new self('Carry-over and the seniority bonus need a yearly balance (days per year).');
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
