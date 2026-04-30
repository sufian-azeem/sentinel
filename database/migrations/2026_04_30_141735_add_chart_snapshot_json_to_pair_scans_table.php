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
        Schema::table('pair_scans', function (Blueprint $table) {
            $table->json('chart_snapshot_json')->nullable()->after('conditions_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pair_scans', function (Blueprint $table) {
            $table->dropColumn('chart_snapshot_json');
        });
    }
};
