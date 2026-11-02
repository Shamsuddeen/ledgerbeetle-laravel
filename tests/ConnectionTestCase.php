<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Tests;

use LedgerBeetle\Laravel\LedgerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The app's default connection and ledger.database_connection point at the
 * same SQLite file, as two connections to one MySQL database would.
 */
abstract class ConnectionTestCase extends Orchestra
{
    protected string $database;

    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->database = sys_get_temp_dir().'/ledgerbeetle-connection-test.sqlite';
        @unlink($this->database);
        touch($this->database);

        foreach (['app', 'ledger'] as $name) {
            $app['config']->set("database.connections.{$name}", [
                'driver' => 'sqlite',
                'database' => $this->database,
                'foreign_key_constraints' => true,
            ]);
        }

        $app['config']->set('database.default', 'app');
        $app['config']->set('ledger.database_connection', 'ledger');
        $app['config']->set('ledger.gateway', 'memory');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->database);
    }
}
