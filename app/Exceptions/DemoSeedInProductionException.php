<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A demo seeder (Database\Seeders\DemoSeeder) was run in production. Thrown
 * before anything is written.
 */
class DemoSeedInProductionException extends RuntimeException
{
    /**
     * @param  list<class-string>  $configuration  the seeders that may run in production
     */
    public function __construct(string $seeder, array $configuration)
    {
        $names = implode(', ', array_map(fn (string $class) => class_basename($class), $configuration));

        parent::__construct(
            class_basename($seeder).' writes demo data and refuses to run in production, even with --force. '
            ."The production seed is the configuration seeders only, run by class: {$names}. See docs/GO_LIVE.md, step 2."
        );
    }
}
