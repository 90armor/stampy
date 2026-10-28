<?php

namespace Database\Seeders;

use App\Exceptions\DemoSeedInProductionException;
use Illuminate\Database\Seeder;

/**
 * A seeder that writes demo data — made-up employees with the password
 * "password", invented punches in the append-only log, holidays on invented
 * dates, sample departments and positions. Every one refuses to run in
 * production, before writing anything, --force or not (--force only skips
 * db:seed's confirmation prompt; this check is inside the seeder).
 *
 * The check is in __invoke(), which is how both `db:seed` and
 * `$this->call()` run a seeder, so a demo seeder only has to extend this
 * class. The configuration seeders that GO_LIVE.md runs in production
 * (CONFIGURATION) extend Seeder directly.
 */
abstract class DemoSeeder extends Seeder
{
    /** The seeders allowed in production, run by class (docs/GO_LIVE.md, step 2). */
    public const CONFIGURATION = [RoleSeeder::class, LeaveTypeSeeder::class, WorkScheduleSeeder::class];

    public function __invoke(array $parameters = [])
    {
        if (app()->isProduction()) {
            throw new DemoSeedInProductionException(static::class, self::CONFIGURATION);
        }

        return parent::__invoke($parameters);
    }
}
