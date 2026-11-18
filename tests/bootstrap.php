<?php

require __DIR__.'/../vendor/autoload.php';

$testUrl = getenv('TEST_DATABASE_URL') ?: '';
$database = parse_url($testUrl);

if (! is_array($database)
    || ! in_array($database['scheme'] ?? '', ['postgres', 'postgresql', 'pgsql'], true)
    || rawurldecode($database['path'] ?? '') !== '/pixelforge_test'
    || rawurldecode($database['user'] ?? '') !== 'pixelforge_test'
    || isset($database['query']) && $database['query'] !== 'sslmode=disable'
) {
    throw new RuntimeException('TEST_DATABASE_URL must use the dedicated pixelforge_test database and role.');
}

if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    throw new RuntimeException('Clear the Laravel configuration cache before running tests.');
}

foreach (['DATABASE_URL', 'DB_URL'] as $name) {
    putenv("{$name}={$testUrl}");
    $_ENV[$name] = $testUrl;
}

$_SERVER = array_replace($_SERVER, $_ENV);
