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
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pair_scan_id')->constrained('pair_scans')->cascadeOnDelete();
            $table->string('pair', 20);
            $table->string('timeframe', 5);
            $table->string('strategy', 30);
            $table->string('entry_type', 20);
            $table->string('reason')->nullable();
            $table->decimal('entry_price', 20, 8);
            $table->decimal('sl_price', 20, 8)->nullable();
            $table->decimal('tp1_price', 20, 8)->nullable();
            $table->decimal('tp2_price', 20, 8)->nullable();
            $table->decimal('risk_pct', 8, 4)->default(0);
            $table->dateTime('candle_time');
            $table->tinyInteger('candles_ago')->default(1);
            $table->decimal('screener_score', 10, 6)->default(0);
            $table->string('confluence', 50)->default('');
            $table->json('conditions_json');
            $table->enum('status', ['active', 'tp1_hit', 'tp2_hit', 'sl_hit', 'expired'])->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->index('pair');
            $table->index('status');
            $table->index('pair_scan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
