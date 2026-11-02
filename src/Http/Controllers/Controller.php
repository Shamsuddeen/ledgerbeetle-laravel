<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Support\Money;

abstract class Controller
{
    /** Amounts arrive as decimal strings ("12.50") so no float ever touches them. */
    protected const AMOUNT = ['string', 'regex:/^\d+(\.\d+)?$/'];

    /**
     * The client's Idempotency-Key header; required for anything that moves money.
     */
    protected function idempotencyKey(Request $request, bool $required = true): ?string
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            if (! $required) {
                return null;
            }

            throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header is required.']);
        }

        if (strlen($key) > 200) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header may not exceed 200 characters.']);
        }

        return $key;
    }

    protected function money(string $amount, LedgerAccount $account, string $field = 'amount'): Money
    {
        try {
            return Money::parse($amount, $account->currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /**
     * 201 when this request created the record, 200 when it replayed one.
     */
    protected function created(JsonResource $resource, Model $model): JsonResponse
    {
        return $resource->response()->setStatusCode($model->wasRecentlyCreated ? 201 : 200);
    }
}
