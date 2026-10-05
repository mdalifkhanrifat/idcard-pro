<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An image element shows either its own uploaded file (image_path) or a library image.
     * A library image in use cannot be deleted.
     */
    public function up(): void
    {
        Schema::table('card_template_elements', function (Blueprint $table) {
            $table->foreignId('library_image_id')->nullable()->after('image_path')->constrained('tenant_images')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_template_elements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('library_image_id');
        });
    }
};
