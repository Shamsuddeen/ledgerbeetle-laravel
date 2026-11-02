<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgerBeetle\Laravel\Http\Resources\HoldResource;
use LedgerBeetle\Laravel\Http\Resources\TransferResource;
use LedgerBeetle\Laravel\Ledger;
use LedgerBeetle\Laravel\Models\LedgerHold;

class HoldController extends Controller
{
    public function __construct(
        private readonly Ledger $ledger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
            'amount' => ['required', ...self::AMOUNT],
            'timeout_seconds' => ['nullable', 'integer', 'between:0,4294967295'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $from = $this->ledger->account($data['from']);

        $hold = $this->ledger->hold(
            $from, $data['to'], $this->money($data['amount'], $from), (int) ($data['timeout_seconds'] ?? 0),
            $this->idempotencyKey($request), $data['reference'] ?? null,
        );

        return $this->created(new HoldResource($hold), $hold);
    }

    public function show(LedgerHold $hold): HoldResource
    {
        return new HoldResource($hold);
    }

    /**
     * Holds settle once, so the Idempotency-Key header is optional here.
     */
    public function capture(Request $request, LedgerHold $hold): JsonResponse
    {
        $data = $request->validate(['amount' => ['nullable', ...self::AMOUNT]]);
        $amount = isset($data['amount']) ? $this->money($data['amount'], $hold->debitAccount)->minor : null;

        $transfer = $this->ledger->capture($hold, $amount, $this->idempotencyKey($request, required: false));

        return $this->created(new TransferResource($transfer), $transfer);
    }

    public function release(Request $request, LedgerHold $hold): JsonResponse
    {
        $transfer = $this->ledger->release($hold, $this->idempotencyKey($request, required: false));

        return $this->created(new TransferResource($transfer), $transfer);
    }
}
