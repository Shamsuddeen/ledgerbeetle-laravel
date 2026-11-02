<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LedgerBeetle\Laravel\Enums\AccountType;

/**
 * Descriptive metadata for a TigerBeetle account. Balances live in TB only;
 * read them through AccountService::balance().
 *
 * @property int $id
 * @property string $tb_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string $currency
 * @property int $ledger
 * @property int $tb_code
 * @property int $flags
 * @property int|null $parent_id
 * @property array<string, mixed>|null $metadata
 * @property bool $is_active
 */
class LedgerAccount extends Model
{
    protected $table = 'ledger_accounts';

    protected $guarded = ['id'];

    /** Column defaults, so freshly created models carry them too. */
    protected $attributes = ['flags' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'ledger' => 'integer',
            'tb_code' => 'integer',
            'flags' => 'integer',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(LedgerHold::class, 'debit_account_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
