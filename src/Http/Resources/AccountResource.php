<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Support\Flags;

/**
 * @mixin LedgerAccount
 */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'currency' => $this->currency,
            'parent' => $this->parent?->code,
            'prevent_overdraft' => ($this->flags & $this->type->overdraftFlag()) !== 0,
            'balance_history' => Flags::has($this->flags, Flags::ACCOUNT_HISTORY),
            'is_active' => $this->is_active,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
