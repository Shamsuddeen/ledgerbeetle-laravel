<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Exceptions;

/**
 * The request did not get a definitive answer from TigerBeetle (timeout,
 * eviction, shutdown). It may or may not have been applied; retry with the
 * same idempotency key to find out.
 */
class ClusterUnavailable extends LedgerException {}
