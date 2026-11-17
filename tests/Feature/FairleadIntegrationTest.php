<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FairleadIntegrationTest extends TestCase
{
    public function test_tests_use_an_isolated_postgres_database()
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('pixelforge_test', DB::connection()->getDatabaseName());
        $this->assertSame('pixelforge_test', DB::selectOne('select current_user as name')->name);
    }

    public function test_test_bootstrap_refuses_the_application_database()
    {
        $process = new Process([PHP_BINARY, '-r', "require 'tests/bootstrap.php';"], base_path(), [
            'TEST_DATABASE_URL' => 'postgres://pixelforge@db:5432/pixelforge',
        ]);
        $process->run();

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('dedicated pixelforge_test database and role', $process->getOutput().$process->getErrorOutput());
    }

    public function test_fairlead_smtp_conventions_configure_laravel_mail()
    {
        $this->assertSame('mail', config('mail.mailers.smtp.host'));
        $this->assertSame('1025', config('mail.mailers.smtp.port'));
        $this->assertSame('hello@pixelforge.fl.wb', config('mail.from.address'));
    }

    public function test_forwarded_https_is_trusted_from_a_private_proxy()
    {
        Route::get('/_test/secure', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/_test/secure')
            ->assertOk()
            ->assertSee('secure');
    }

    public function test_forwarded_https_is_ignored_from_an_untrusted_proxy()
    {
        Route::get('/_test/secure', fn (Request $request) => $request->isSecure() ? 'secure' : 'insecure');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('http://localhost/_test/secure')
            ->assertOk()
            ->assertSee('insecure');
    }
}
