<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown for file-level import problems (missing file, missing configured
 * columns) that should stop the whole import with a clear message —
 * as opposed to a bad individual row, which is reported and skipped.
 */
class AttendanceImportException extends RuntimeException {}
