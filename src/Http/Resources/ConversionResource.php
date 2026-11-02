<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LedgerBeetle\Laravel\Models\LedgerConversion;

/**
 * @mixin LedgerConversion
 */
class ConversionResource extends JsonResource
{
    use MoneyFormat;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status->value,
            'from' => $this->sourceAccount->code,
            'to' => $this->targetAccount->code,
            'source' => $this->money($this->source_amount, $this->source_currency),
            'target' => $this->money($this->target_amount, $this->target_currency),
            'rate' => $this->rate,
            'fee' => $this->money($this->fee_amount, $this->source_currency),
            'reverses_id' => $this->reverses_id,
            'reference' => $this->reference,
            'failure_reason' => $this->failure_reason === null ? null : strtolower($this->failure_reason),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
