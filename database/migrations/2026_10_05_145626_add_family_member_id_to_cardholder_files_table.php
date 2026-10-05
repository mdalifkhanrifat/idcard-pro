<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Family members keep their own photo and signature among the cardholder's files.
     */
    public function up(): void
    {
        Schema::table('cardholder_files', function (Blueprint $table) {
            // Null for the cardholder's own files
            $table->foreignId('family_member_id')->nullable()->after('cardholder_id')->constrained()->cascadeOnDelete();
            $table->index(['family_member_id', 'collection', 'replaced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cardholder_files', function (Blueprint $table) {
            // The foreign key uses the index, so it goes first
            $table->dropForeign(['family_member_id']);
            $table->dropIndex(['family_member_id', 'collection', 'replaced_at']);
            $table->dropColumn('family_member_id');
        });
    }
};
