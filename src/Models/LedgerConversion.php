<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Models\Concerns\UsesLedgerConnection;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property TransferStatus $status
 * @property int $source_account_id
 * @property int $target_account_id
 * @property int $source_amount
 * @property string $source_currency
 * @property int $target_amount
 * @property string $target_currency
 * @property string $rate
 * @property int $fee_amount
 * @property int|null $fee_account_id
 * @property int|null $reverses_id
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class LedgerConversion extends Model
{
    use UsesLedgerConnection;

    protected $table = 'ledger_conversions';

    protected $guarded = ['id'];

    /** Column defaults, so freshly created models carry them too. */
    protected $attributes = ['fee_amount' => 0];

    protected $casts = [
        'status' => TransferStatus::class,
        'source_amount' => 'integer',
        'target_amount' => 'integer',
        'fee_amount' => 'integer',
        'metadata' => 'array',
    ];

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'source_account_id');
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'target_account_id');
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'fee_account_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(LedgerTransfer::class, 'conversion_id')->orderBy('id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
