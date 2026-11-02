<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LedgerBeetle\Laravel\Models\LedgerTransfer;

/**
 * @mixin LedgerTransfer
 */
class TransferResource extends JsonResource
{
    use MoneyFormat;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'idempotency_key' => $this->idempotency_key,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'from' => $this->debitAccount->code,
            'to' => $this->creditAccount->code,
            'amount' => $this->money($this->amount, $this->currency),
            'reversed' => $this->money($this->reversed_amount, $this->currency),
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason === null ? null : strtolower($this->failure_reason),
            'hold_id' => $this->hold_id,
            'reverses_id' => $this->reverses_id,
            'conversion_id' => $this->conversion_id,
            'tb_id' => $this->tb_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
