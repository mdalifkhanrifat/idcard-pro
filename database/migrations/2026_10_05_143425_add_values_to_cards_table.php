<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The text printed on the card, fixed at issue (CardDataResolver fields), so a reprint shows
     * exactly what the card showed; changed details need a new card (e.g. reason "correction").
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->json('values')->nullable()->after('layout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('values');
        });
    }
};
