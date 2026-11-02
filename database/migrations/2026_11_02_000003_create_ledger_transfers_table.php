<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metadata for every transfer sent to TigerBeetle. Balances are never
        // computed from this table; TigerBeetle is the source of truth.
        Schema::create('ledger_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('tb_id', 40)->unique();
            $table->string('idempotency_key')->unique();
            $table->char('fingerprint', 64);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->foreignId('debit_account_id')->constrained('ledger_accounts');
            $table->foreignId('credit_account_id')->constrained('ledger_accounts');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedInteger('ledger');
            $table->unsignedSmallInteger('tb_code');
            $table->foreignId('hold_id')->nullable()->constrained('ledger_holds');
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_transfers');
            $table->unsignedBigInteger('reversed_amount')->default(0);
            $table->string('reference')->nullable();
            $table->string('failure_reason', 80)->nullable();
            $table->unsignedBigInteger('tb_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['debit_account_id', 'created_at']);
            $table->index(['credit_account_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transfers');
    }
};
