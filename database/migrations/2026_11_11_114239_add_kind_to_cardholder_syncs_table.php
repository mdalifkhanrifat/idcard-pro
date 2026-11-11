<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run of the HR sync is either an upsert (POST /sync/cardholders) or a deactivation
 * (POST /sync/cardholders/deactivate); a deactivation also counts the numbers no one has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cardholder_syncs', function (Blueprint $table) {
            $table->string('kind', 20)->default('upsert')->after('user_id');
            // Deactivation only: employee numbers that matched nobody
            $table->unsignedInteger('not_found_count')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('cardholder_syncs', function (Blueprint $table) {
            $table->dropColumn(['kind', 'not_found_count']);
        });
    }
};
