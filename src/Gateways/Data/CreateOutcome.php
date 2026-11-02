<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

use LedgerBeetle\Laravel\Enums\CreateAccountResult;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;

final readonly class CreateOutcome
{
    /**
     * @param  int  $timestamp  TB timestamp of the created (or, for EXISTS, original) object
     */
    public function __construct(
        public CreateAccountResult|CreateTransferResult $result,
        public int $timestamp = 0,
    ) {}

    public function ok(): bool
    {
        return $this->result->isSuccess();
    }
}
