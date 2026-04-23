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
        Schema::create('executed_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('signal_id')->nullable();
            $table->string('exchange', 30);
            $table->string('exchange_order_id', 100)->nullable();
            $table->string('pair', 20);
            $table->enum('side', ['long', 'short']);
            $table->enum('order_type', ['market', 'limit']);
            $table->tinyInteger('leverage')->default(1);
            $table->decimal('quantity', 20, 8);
            $table->decimal('notional_usd', 20, 8);
            $table->decimal('entry_price', 20, 8);
            $table->dateTime('entry_filled_at')->nullable();
            $table->enum('entry_fill_status', ['pending', 'partial', 'filled', 'cancelled'])->default('pending');
            $table->decimal('entry_fill_qty', 20, 8)->nullable();
            $table->decimal('entry_fee', 20, 8)->nullable();
            $table->decimal('sl_price', 20, 8)->nullable();
            $table->string('sl_order_id', 100)->nullable();
            $table->decimal('tp1_price', 20, 8)->nullable();
            $table->string('tp1_order_id', 100)->nullable();
            $table->decimal('tp2_price', 20, 8)->nullable();
            $table->string('tp2_order_id', 100)->nullable();
            $table->dateTime('breakeven_moved_at')->nullable();
            $table->json('trailing_tp_json')->nullable();
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->dateTime('exit_filled_at')->nullable();
            $table->decimal('exit_fee', 20, 8)->nullable();
            $table->decimal('total_fees_usd', 20, 8)->nullable();
            $table->decimal('pnl_pct', 8, 4)->nullable();
            $table->decimal('pnl_usd', 20, 8)->nullable();
            $table->decimal('pnl_r', 8, 4)->nullable();
            $table->enum('status', ['pending', 'open', 'closed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('signal_id');
            $table->index('exchange');
            $table->index('status');
            $table->index('pair');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('executed_trades');
    }
};
