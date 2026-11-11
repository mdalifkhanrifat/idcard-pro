<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The id an HR system keeps for a family member, so a sync finds the same person again whatever else changed
 * (name, relation). Optional; unique within the cardholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->string('external_id', 100)->nullable()->after('cardholder_id');
            $table->unique(['cardholder_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropUnique(['cardholder_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
