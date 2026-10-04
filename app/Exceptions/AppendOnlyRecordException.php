<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An append-only record (LeaveAdjustment, ApprovalStep) can't be updated or
 * deleted — a wrong one is corrected by adding another (a reversing
 * adjustment), so the history of what was decided stays whole. Enforced in
 * the model's updating/deleting events; a database cascade or a
 * query-builder mass update/delete skips them, and nothing in the app uses
 * either on these tables.
 */
class AppendOnlyRecordException extends RuntimeException
{
    public static function for(Model $record, string $action): self
    {
        return new self(class_basename($record)." records are append-only and can't be {$action} — add a correcting record instead.");
    }
}
