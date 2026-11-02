<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Models\Concerns;

/**
 * Ledger rows use ledger.database_connection when it is set, so they commit
 * on their own instead of joining (and rolling back with) the app's open
 * transaction on the default connection.
 */
trait UsesLedgerConnection
{
    public function getConnectionName(): ?string
    {
        return config('ledger.database_connection') ?? parent::getConnectionName();
    }
}
