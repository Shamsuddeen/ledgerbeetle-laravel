<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Http\Resources\AccountResource;
use LedgerBeetle\Laravel\Http\Resources\TransferResource;
use LedgerBeetle\Laravel\Ledger;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Support\BalanceSnapshot;

class AccountController extends Controller
{
    public function __construct(
        private readonly Ledger $ledger,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'parent' => ['nullable', 'string'],
            'prevent_overdraft' => ['nullable', 'boolean'],
            'balance_history' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $account = $this->ledger->createAccount(
            $data['code'], $data['name'], AccountType::from($data['type']), $data['currency'] ?? null,
            $data['parent'] ?? null, $data['prevent_overdraft'] ?? null, $data['metadata'] ?? [], $data['balance_history'] ?? null,
        );

        return $this->created(new AccountResource($account), $account);
    }

    public function show(string $code): AccountResource
    {
        $account = $this->ledger->account($code);

        return (new AccountResource($account))->additional(['balance' => $this->ledger->balance($account)->toArray()]);
    }

    public function balance(Request $request, string $code): JsonResponse
    {
        $request->validate(['at' => ['nullable', 'date']]);

        $balance = $request->filled('at')
            ? $this->ledger->balanceAt($code, Carbon::parse($request->string('at')))
            : $this->ledger->balance($code);

        return response()->json(['data' => $balance->toArray() + ['as_of' => $request->input('at')]]);
    }

    public function history(Request $request, string $code): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,1000'],
        ]);

        $snapshots = $this->ledger->balanceHistory(
            $code,
            $request->filled('from') ? Carbon::parse($request->string('from')) : null,
            $request->filled('to') ? Carbon::parse($request->string('to')) : null,
            $request->integer('limit', 100),
        );

        return response()->json(['data' => array_map(fn (BalanceSnapshot $s) => $s->toArray(), $snapshots)]);
    }

    public function transfers(Request $request, string $code): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'between:1,200']]);

        $transfers = LedgerTransfer::query()
            ->forAccount($this->ledger->account($code))
            ->with(['debitAccount', 'creditAccount'])
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return TransferResource::collection($transfers);
    }
}
