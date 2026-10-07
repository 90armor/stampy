<?php

namespace App\Exceptions;

/**
 * A decision on a leave request that has moved on since the actor saw it
 * (StaleDecisionException, which the overtime lifecycle throws directly).
 */
class StaleLeaveDecisionException extends StaleDecisionException {}
