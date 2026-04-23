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
        Schema::create('screener_pairs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screener_run_id');
            $table->string('symbol', 30);
            $table->string('pair', 20);
            $table->decimal('price', 20, 8);
            $table->decimal('rvol', 10, 4)->default(0);
            $table->decimal('score', 10, 6)->default(0);
            $table->string('alligator_tf', 5)->nullable();
            $table->tinyInteger('bullish_count')->default(0);
            $table->string('confluence', 50)->default('');
            $table->tinyInteger('qualified')->default(0);
            $table->string('disqualify_reason', 100)->nullable();
            $table->json('tf_data_json');
            $table->json('filters_json');
            $table->timestamp('created_at')->useCurrent();

            $table->index('screener_run_id');
            $table->index('qualified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screener_pairs');
    }
};
