<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgerBeetle\Laravel\Http\Resources\TransferResource;
use LedgerBeetle\Laravel\Ledger;
use LedgerBeetle\Laravel\Models\LedgerTransfer;

class TransferController extends Controller
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
            'reference' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'integer', 'between:1,65535'],
        ]);
        $key = $this->idempotencyKey($request);
        $from = $this->ledger->account($data['from']);

        $transfer = $this->ledger->transfer(
            $from, $data['to'], $this->money($data['amount'], $from), $key, $data['reference'] ?? null, $data['code'] ?? null,
        );

        return $this->created(new TransferResource($transfer), $transfer);
    }

    public function show(LedgerTransfer $transfer): TransferResource
    {
        return new TransferResource($transfer);
    }

    public function reverse(Request $request, LedgerTransfer $transfer): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', ...self::AMOUNT],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = isset($data['amount']) ? $this->money($data['amount'], $transfer->debitAccount)->minor : null;
        $reversal = $this->ledger->reverse($transfer, $amount, $this->idempotencyKey($request), $data['reason'] ?? null);

        return $this->created(new TransferResource($reversal), $reversal);
    }

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'between:1,8189'],
            'items.*.from' => ['required', 'string'],
            'items.*.to' => ['required', 'string'],
            'items.*.amount' => ['required', ...self::AMOUNT],
            'items.*.idempotency_key' => ['required', 'string', 'max:200', 'distinct'],
            'items.*.reference' => ['nullable', 'string', 'max:255'],
        ]);

        $items = [];
        foreach ($data['items'] as $i => $item) {
            $from = $this->ledger->account($item['from']);
            $items[] = ['amount' => $this->money($item['amount'], $from, "items.{$i}.amount")->minor] + $item;
        }

        return response()->json(['data' => [
            'batch_id' => $this->ledger->queueBatch($items),
            'idempotency_keys' => array_column($items, 'idempotency_key'),
        ]], 202);
    }
}
