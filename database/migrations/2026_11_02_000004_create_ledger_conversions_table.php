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
        // A currency conversion: linked legs on two ledgers, recorded in
        // ledger_transfers with kind "conversion" and this row's id.
        Schema::create('ledger_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->foreignId('source_account_id')->constrained('ledger_accounts');
            $table->foreignId('target_account_id')->constrained('ledger_accounts');
            $table->unsignedBigInteger('source_amount');
            $table->char('source_currency', 3);
            $table->unsignedBigInteger('target_amount');
            $table->char('target_currency', 3);
            // Target units per source unit, as a decimal string; never a float.
            $table->string('rate', 40);
            $table->unsignedBigInteger('fee_amount')->default(0);
            $table->foreignId('fee_account_id')->nullable()->constrained('ledger_accounts');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('ledger_conversions');
            $table->string('reference')->nullable();
            $table->string('failure_reason', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_account_id', 'created_at']);
            $table->index(['target_account_id', 'created_at']);
        });

        Schema::table('ledger_transfers', function (Blueprint $table) {
            $table->foreignId('conversion_id')->nullable()->after('reverses_id')->constrained('ledger_conversions');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversion_id');
        });
        Schema::dropIfExists('ledger_conversions');
    }
};
