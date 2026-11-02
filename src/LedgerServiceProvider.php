<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use LedgerBeetle\Laravel\Contracts\ExchangeRates;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Gateways\InMemoryGateway;
use LedgerBeetle\Laravel\Http\ErrorResponse;
use LedgerBeetle\Laravel\Gateways\NativeGateway;
use LedgerBeetle\Laravel\Native\TbClient;
use LedgerBeetle\Laravel\Services\AccountService;
use LedgerBeetle\Laravel\Services\ConversionService;
use LedgerBeetle\Laravel\Services\HoldService;
use LedgerBeetle\Laravel\Services\TransferService;
use LedgerBeetle\Laravel\Support\ConfigExchangeRates;
use LedgerBeetle\Laravel\Support\Ledgers;

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledger.php', 'ledger');

        $this->app->singleton(Ledgers::class, fn (Application $app) => new Ledgers($app['config']['ledger.ledgers']));

        $this->app->singleton(TigerBeetleGateway::class, fn (Application $app) => match ($app['config']['ledger.gateway']) {
            'memory' => new InMemoryGateway,
            'tigerbeetle' => $this->nativeGateway($app['config']['ledger.tigerbeetle']),
            default => throw new \InvalidArgumentException("Unknown ledger gateway [{$app['config']['ledger.gateway']}]."),
        });

        $this->app->singleton(AccountService::class, fn (Application $app) => new AccountService(
            $app->make(TigerBeetleGateway::class),
            $app->make(Ledgers::class),
            $app['config']['ledger.default_currency'],
            $app['config']['ledger.prevent_overdraft'],
            (bool) $app['config']->get('ledger.balance_history', true),
        ));

        $this->app->when([TransferService::class, HoldService::class])
            ->needs('$defaultCode')
            ->giveConfig('ledger.default_transfer_code');

        $this->app->bindIf(ExchangeRates::class, fn (Application $app) => new ConfigExchangeRates($app['config']['ledger.fx.rates']), true);

        $this->app->when(ConversionService::class)
            ->needs('$preventShortPositions')
            ->giveConfig('ledger.fx.prevent_short', false);

        $this->app->singleton(TransferService::class);
        $this->app->singleton(ConversionService::class);
        $this->app->singleton(HoldService::class);
        $this->app->singleton(Ledger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app['config']->get('ledger.api.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/ledger.php');

            $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
                if (method_exists($handler, 'renderable')) {
                    $handler->renderable(fn (LedgerException $e, Request $request) => $request->routeIs('ledger.*') ? ErrorResponse::for($e) : null);
                }
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/ledger.php' => config_path('ledger.php')], 'ledger-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'ledger-migrations');

            $this->commands([
                Console\SeedChartOfAccounts::class,
                Console\ReconcileCommand::class,
                Console\SweepHoldsCommand::class,
            ]);
        }
    }

    /**
     * @param  array{cluster_id: string, addresses: list<string>, timeout_seconds: float, library_path: ?string}  $config
     */
    private function nativeGateway(array $config): NativeGateway
    {
        return new NativeGateway(fn () => new TbClient(
            $config['library_path'] ?: self::bundledLibrary(),
            (string) $config['cluster_id'],
            array_values($config['addresses']),
            (int) round($config['timeout_seconds'] * 1000),
        ));
    }

    /**
     * Where bin/build-native.sh puts the shim for this platform.
     */
    public static function bundledLibrary(): string
    {
        $platform = match (PHP_OS_FAMILY.'-'.php_uname('m')) {
            'Darwin-arm64' => 'aarch64-macos',
            'Darwin-x86_64' => 'x86_64-macos',
            'Linux-aarch64', 'Linux-arm64' => 'aarch64-linux',
            default => 'x86_64-linux',
        };

        return dirname(__DIR__)."/resources/lib/{$platform}/libledgerbeetle_tb.".(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
    }
}
