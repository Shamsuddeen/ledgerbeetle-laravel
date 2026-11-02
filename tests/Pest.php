<?php

use LedgerBeetle\Laravel\Tests\ConnectionTestCase;
use LedgerBeetle\Laravel\Tests\IntegrationTestCase;
use LedgerBeetle\Laravel\Tests\TestCase;

// uses() rather than pest()->extend(): Pest 2 (the last to run on Laravel 10) has no pest().
uses(TestCase::class)->in('Unit', 'Feature');
uses(ConnectionTestCase::class)->in('Connection');
uses(IntegrationTestCase::class)->group('integration')->in('Integration');
