<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Models\Concerns\UsesLedgerConnection;

/**
 * Local record of a transfer sent to TigerBeetle.
 *
 * @property int $id
 * @property string $tb_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property TransferKind $kind
 * @property TransferStatus $status
 * @property int $debit_account_id
 * @property int $credit_account_id
 * @property int $amount
 * @property string $currency
 * @property int $ledger
 * @property int $tb_code
 * @property int|null $hold_id
 * @property int|null $reverses_id
 * @property int|null $conversion_id
 * @property int $reversed_amount
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property int|null $tb_timestamp
 * @property array<string, mixed>|null $metadata
 */
class LedgerTransfer extends Model
{
    use UsesLedgerConnection;

    protected $table = 'ledger_transfers';

    protected $guarded = ['id'];

    /** Column defaults, so freshly created models carry them too. */
    protected $attributes = ['reversed_amount' => 0];

    protected $casts = [
        'kind' => TransferKind::class,
        'status' => TransferStatus::class,
        'amount' => 'integer',
        'ledger' => 'integer',
        'tb_code' => 'integer',
        'reversed_amount' => 'integer',
        'tb_timestamp' => 'integer',
        'metadata' => 'array',
    ];

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'credit_account_id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(LedgerHold::class, 'hold_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(LedgerConversion::class, 'conversion_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_id');
    }

    public function reversibleAmount(): int
    {
        return $this->amount - $this->reversed_amount;
    }

    public function scopeForAccount(Builder $query, LedgerAccount|int $account): void
    {
        $id = $account instanceof LedgerAccount ? $account->id : $account;

        $query->where(fn (Builder $q) => $q->where('debit_account_id', $id)->orWhere('credit_account_id', $id));
    }
}
