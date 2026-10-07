<?php

namespace Tests\Feature;

use App\Exceptions\DemoSeedInProductionException;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\AttendanceLogSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\LeaveSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WorkScheduleSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * The demo seeders refuse to run in production (DemoSeeder), even with
 * --force and before writing anything; the configuration seeders that
 * docs/GO_LIVE.md runs there still do.
 */
class DemoSeederProductionTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO = [
        DatabaseSeeder::class,
        AdminUserSeeder::class,
        DepartmentSeeder::class,
        PositionSeeder::class,
        EmployeeSeeder::class,
        HolidaySeeder::class,
        AttendanceLogSeeder::class,
        LeaveSeeder::class,
    ];

    public function test_every_demo_seeder_refuses_in_production_even_with_force_and_writes_nothing(): void
    {
        $this->app['env'] = 'production';

        foreach (self::DEMO as $seeder) {
            try {
                $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->run();
                $this->fail(class_basename($seeder).' ran in production.');
            } catch (DemoSeedInProductionException $e) {
                $this->assertStringContainsString(class_basename($seeder).' writes demo data', $e->getMessage());
                $this->assertStringContainsString('RoleSeeder, LeaveTypeSeeder, WorkScheduleSeeder', $e->getMessage());
                $this->assertStringContainsString('docs/GO_LIVE.md', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->rowsInAllTables());
    }

    public function test_plain_db_seed_refuses_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(DemoSeedInProductionException::class);

        $this->artisan('db:seed', ['--force' => true])->run();
    }

    public function test_the_configuration_seeders_still_run_in_production(): void
    {
        $this->app['env'] = 'production';

        foreach (DemoSeeder::CONFIGURATION as $seeder) {
            $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->assertSuccessful();
        }

        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(1, DB::table('work_schedules')->count());
        $this->assertGreaterThan(0, DB::table('leave_types')->count());
        $this->assertSame(0, DB::table('employees')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_the_demo_seeders_still_run_outside_production(): void
    {
        $this->artisan('db:seed', ['--class' => DepartmentSeeder::class])->assertSuccessful();

        $this->assertSame(2, DB::table('departments')->count());
    }

    /**
     * A seeder added later is either one of the configuration seeders or a
     * DemoSeeder — never a plain Seeder that would run in production unnoticed.
     */
    public function test_every_seeder_is_either_configuration_or_demo(): void
    {
        foreach (glob(database_path('seeders/*.php')) as $file) {
            $class = 'Database\\Seeders\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Seeder::class)) {
                continue;
            }

            $this->assertTrue(
                in_array($class, DemoSeeder::CONFIGURATION, true) || $reflection->isSubclassOf(DemoSeeder::class),
                class_basename($class).' must extend DemoSeeder or be listed in DemoSeeder::CONFIGURATION.',
            );
        }

        $this->assertSame([RoleSeeder::class, LeaveTypeSeeder::class, WorkScheduleSeeder::class], DemoSeeder::CONFIGURATION);
    }

    private function rowsInAllTables(): int
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn (string $table) => $table === 'migrations')
            ->sum(fn (string $table) => DB::table($table)->count());
    }
}
