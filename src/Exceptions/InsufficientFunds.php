<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Exceptions;

use LedgerBeetle\Laravel\Enums\CreateTransferResult;

class InsufficientFunds extends TransferRejected
{
    public function __construct(CreateTransferResult $result)
    {
        parent::__construct($result, 'Insufficient funds: the transfer would overdraw a guarded account.');
    }
}
