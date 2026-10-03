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
        Schema::table('cardholders', function (Blueprint $table) {
            // Values of the tenant's custom fields, keyed by custom_fields.key
            $table->json('custom_fields')->nullable()->after('company_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cardholders', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
