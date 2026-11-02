<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Tests;

/**
 * Runs against a real cluster. Needs TB_ADDRESSES and the native shim from
 * bin/build-native.sh (or TB_CLIENT_LIB pointing at one).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TB_ADDRESSES')) {
            $this->markTestSkipped('Set TB_ADDRESSES to run integration tests against TigerBeetle.');
        }

        parent::setUp();
    }

    public function passSeconds(int $seconds): void
    {
        sleep($seconds);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ledger.gateway', 'tigerbeetle');
        $app['config']->set('ledger.tigerbeetle.addresses', explode(',', getenv('TB_ADDRESSES')));
        $app['config']->set('ledger.tigerbeetle.library_path', getenv('TB_CLIENT_LIB') ?: null);
    }
}
