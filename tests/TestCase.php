<?php

namespace Tests;

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
}
