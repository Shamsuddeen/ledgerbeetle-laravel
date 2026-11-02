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
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            // u128 TigerBeetle id as a decimal string.
            $table->string('tb_id', 40)->unique();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->string('type', 16);
            $table->char('currency', 3);
            $table->unsignedInteger('ledger');
            $table->unsignedSmallInteger('tb_code');
            $table->unsignedSmallInteger('flags')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
