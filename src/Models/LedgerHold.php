<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LedgerBeetle\Laravel\Enums\HoldStatus;

/**
 * Funds reserved by a TigerBeetle pending transfer.
 *
 * @property int $id
 * @property string $tb_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property int $debit_account_id
 * @property int $credit_account_id
 * @property int $amount
 * @property int|null $posted_amount
 * @property string $currency
 * @property int $ledger
 * @property int $tb_code
 * @property HoldStatus $status
 * @property int $timeout_seconds
 * @property Carbon|null $expires_at
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class LedgerHold extends Model
{
    protected $table = 'ledger_holds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => HoldStatus::class,
            'amount' => 'integer',
            'posted_amount' => 'integer',
            'ledger' => 'integer',
            'tb_code' => 'integer',
            'timeout_seconds' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'credit_account_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(LedgerTransfer::class, 'hold_id');
    }
}
