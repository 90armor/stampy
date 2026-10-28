<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * overtime_settings is exactly one row, inserted by its migration (production
 * can't run the demo seed): it can't be missing, duplicated or deleted.
 */
class OvertimeSettingsRowException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('The overtime settings row is missing. It\'s created by the create_overtime_settings_table migration — run php artisan migrate.');
    }

    public static function second(): self
    {
        return new self('There is only one overtime settings row; change it rather than adding another.');
    }

    public static function delete(): self
    {
        return new self('The overtime settings row can\'t be deleted; change its values instead.');
    }
}
