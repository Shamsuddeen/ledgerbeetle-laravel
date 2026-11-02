<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgerBeetle\Laravel\Http\Resources\ConversionResource;
use LedgerBeetle\Laravel\Ledger;
use LedgerBeetle\Laravel\Models\LedgerConversion;

class ConversionController extends Controller
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
            'rate' => ['nullable', ...self::AMOUNT],
            'fee' => ['nullable', ...self::AMOUNT],
            'fee_account' => ['required_with:fee', 'nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $from = $this->ledger->account($data['from']);

        $conversion = $this->ledger->convert(
            $from, $data['to'], $this->money($data['amount'], $from), $data['rate'] ?? null,
            $this->idempotencyKey($request),
            isset($data['fee']) ? $this->money($data['fee'], $from, 'fee') : 0,
            $data['fee_account'] ?? null, $data['reference'] ?? null,
        );

        return $this->created(new ConversionResource($conversion), $conversion);
    }

    public function show(LedgerConversion $conversion): ConversionResource
    {
        return new ConversionResource($conversion);
    }

    public function reverse(Request $request, LedgerConversion $conversion): JsonResponse
    {
        $reversal = $this->ledger->reverseConversion($conversion, $this->idempotencyKey($request, required: false));

        return $this->created(new ConversionResource($reversal), $reversal);
    }
}
