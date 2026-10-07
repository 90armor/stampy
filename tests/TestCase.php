<?php

namespace Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // Runs before any trait's setUp (RefreshDatabase's migrate:fresh included), so a wrong database aborts before anything is wiped.
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $configured = (string) $config->get('database.connections.'.$config->get('database.default').'.database');
        $connected = (string) $app['db']->connection()->selectOne('select database() as name')->name;

        foreach ([$configured, $connected] as $name) {
            if (! str_ends_with($name, '_testing')) {
                throw new RuntimeException(
                    "Refusing to run tests: the database resolved to \"{$name}\", not a *_testing database. ".
                    'RefreshDatabase would wipe it. Run the suite as documented in CLAUDE.md (docker compose exec app php artisan test).'
                );
            }
        }

        return $app;
    }

    /**
     * The pinned-instants check (scripts/pinned-instants.sh): with PIN_NOW
     * set, every test starts at that instant. Unset, this does nothing. A
     * test class that pins its own clock does so after this, and wins.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (($pin = getenv('PIN_NOW')) !== false && $pin !== '') {
            $this->travelTo(Carbon::parse($pin));
        }
    }
}
