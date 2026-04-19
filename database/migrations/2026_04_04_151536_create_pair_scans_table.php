<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pair_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screener_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screener_pair_id')->nullable()->constrained('screener_pairs')->nullOnDelete();
            $table->string('pair', 20);
            $table->string('timeframe', 5);
            $table->string('exchange', 30);
            $table->string('strategy', 30);
            $table->integer('candles_fetched')->nullable();
            $table->enum('status', ['scanned', 'skipped', 'error'])->default('scanned');
            $table->json('conditions_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('screener_run_id');
            $table->index('screener_pair_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pair_scans');
    }
};
