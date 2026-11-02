<?php

use LedgerBeetle\Laravel\Tests\IntegrationTestCase;
use LedgerBeetle\Laravel\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->extend(IntegrationTestCase::class)->group('integration')->in('Integration');
