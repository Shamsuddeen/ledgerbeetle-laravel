<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LedgerBeetle\Laravel\Models\LedgerHold;

/**
 * @mixin LedgerHold
 */
class HoldResource extends JsonResource
{
    use MoneyFormat;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status->value,
            'from' => $this->debitAccount->code,
            'to' => $this->creditAccount->code,
            'amount' => $this->money($this->amount, $this->currency),
            'posted' => $this->posted_amount === null ? null : $this->money($this->posted_amount, $this->currency),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason === null ? null : strtolower($this->failure_reason),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
