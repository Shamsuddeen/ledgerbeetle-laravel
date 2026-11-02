<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LedgerBeetle\Laravel\LedgerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    /**
     * Let time pass for the ledger. The in-memory gateway follows Carbon's
     * clock; a real cluster needs real time (see IntegrationTestCase).
     */
    public function passSeconds(int $seconds): void
    {
        $this->travel($seconds)->seconds();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('ledger.gateway', 'memory');
        $app['config']->set('ledger.fx.rates', ['USD/EUR' => '0.92', 'USD/JPY' => '150.5']);
        $app['config']->set('ledger.api.enabled', true);
        $app['config']->set('ledger.api.middleware', ['api']); // no auth in tests
    }
}
