<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

final readonly class AccountSpec
{
    public function __construct(
        public string $id,
        public int $ledger,
        public int $code,
        public int $flags = 0,
        public string $userData128 = '0',
        public int $userData64 = 0,
        public int $userData32 = 0,
    ) {}
}
