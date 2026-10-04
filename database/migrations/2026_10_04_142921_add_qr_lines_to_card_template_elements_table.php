<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: template_sections.qrcode_info, a list of {relation_name, field, label, custom}.
     * Each line is now {field, label} (a data field, optionally prefixed "label:") or {text}.
     */
    public function up(): void
    {
        Schema::table('card_template_elements', function (Blueprint $table) {
            $table->json('qr_lines')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_template_elements', function (Blueprint $table) {
            $table->dropColumn('qr_lines');
        });
    }
};
