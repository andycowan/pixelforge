<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestRunnerIsolationTest extends TestCase
{
    public function test_an_independent_suite_cannot_acquire_the_test_database_lock(): void
    {
        config(['database.connections.isolation_probe' => config('database.connections.pgsql')]);

        try {
            $row = DB::connection('isolation_probe')->selectOne(
                'SELECT pg_try_advisory_lock(CAST(? AS bigint)) AS acquired',
                [self::DATABASE_SUITE_LOCK],
            );

            $this->assertFalse($row->acquired);
        } finally {
            DB::purge('isolation_probe');
        }
    }
}
