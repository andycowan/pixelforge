<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use PDO;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected const DATABASE_SUITE_LOCK = 7_390_041;

    private static ?PDO $databaseSuiteLock = null;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $pdo = $app->make('db')->connection()->getPdo();
        $statement = $pdo->query('SELECT current_database() AS database, current_user AS role');
        $identity = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($identity) || $identity['database'] !== 'pixelforge_test' || $identity['role'] !== 'pixelforge_test') {
            throw new RuntimeException('Integration tests require the dedicated test database and role.');
        }

        if (self::$databaseSuiteLock === null) {
            $pdo->exec("SET statement_timeout = '300s'");
            $pdo->query('SELECT pg_advisory_lock('.self::DATABASE_SUITE_LOCK.')');
            $pdo->exec('SET statement_timeout = 0');
            self::$databaseSuiteLock = $pdo;
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
