<?php

// PHPUnit's <env force="true"> tags in phpunit.xml only override getenv()/$_ENV —
// not $_SERVER. This container sets DB_CONNECTION/DB_HOST/etc. as real environment
// variables (see docker-compose.yml's `app` service), which land in $_SERVER too,
// and Laravel's dotenv repository reads $_SERVER with the highest priority. Without
// this, tests silently ran against the real dev MySQL database — via
// RefreshDatabase, wiping it — instead of the stampy_testing database phpunit.xml
// specifies. Unsetting these lets Laravel's env() resolution fall through to
// $_ENV/getenv(), which PHPUnit's forced <env> values do correctly reach.
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_URL'] as $key) {
    unset($_SERVER[$key]);
}

require __DIR__.'/../vendor/autoload.php';
