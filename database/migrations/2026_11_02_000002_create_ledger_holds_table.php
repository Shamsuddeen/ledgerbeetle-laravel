<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledger.database_connection') ?? $this->connection;
    }

    public function up(): void
    {
        Schema::create('ledger_holds', function (Blueprint $table) {
            $table->id();
            // Id of the TB pending transfer that reserves the funds.
            $table->string('tb_id', 40)->unique();
            $table->string('idempotency_key')->unique();
            $table->char('fingerprint', 64);
            $table->foreignId('debit_account_id')->constrained('ledger_accounts');
            $table->foreignId('credit_account_id')->constrained('ledger_accounts');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('posted_amount')->nullable();
            $table->char('currency', 3);
            $table->unsignedInteger('ledger');
            $table->unsignedSmallInteger('tb_code');
            $table->string('status', 16);
            $table->unsignedInteger('timeout_seconds')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('reference')->nullable();
            $table->string('failure_reason', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['debit_account_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_holds');
    }
};
