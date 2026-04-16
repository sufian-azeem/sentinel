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
        Schema::create('screener_runs', function (Blueprint $table) {
            $table->id();
            $table->string('data_source', 100);
            $table->integer('total_scanned')->default(0);
            $table->integer('total_matched')->default(0);
            $table->json('filters_json');
            $table->enum('status', ['running', 'completed', 'failed', 'expired'])->default('running');
            $table->text('error_message')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screener_runs');
    }
};
