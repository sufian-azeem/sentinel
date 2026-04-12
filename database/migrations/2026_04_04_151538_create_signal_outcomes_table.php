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
        Schema::create('signal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signal_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['tp1_hit', 'tp2_hit', 'sl_hit', 'breakeven', 'expired', 'manual_close']);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->dateTime('exit_time')->nullable();
            $table->decimal('tp1_hit_price', 20, 8)->nullable();
            $table->dateTime('tp1_hit_at')->nullable();
            $table->decimal('tp2_hit_price', 20, 8)->nullable();
            $table->dateTime('tp2_hit_at')->nullable();
            $table->decimal('sl_hit_price', 20, 8)->nullable();
            $table->dateTime('sl_hit_at')->nullable();
            $table->dateTime('breakeven_moved_at')->nullable();
            $table->json('trailing_tp_json')->nullable();
            $table->integer('candles_to_exit')->nullable();
            $table->decimal('pnl_pct', 8, 4)->nullable();
            $table->decimal('pnl_usd', 20, 8)->nullable();
            $table->decimal('pnl_r', 8, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('signal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signal_outcomes');
    }
};
