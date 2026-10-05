<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * New (no legacy table): fonts an organization holds a licence for (e.g. SolaimanLipi, Nikosh),
     * served to its own users through signed links and used by card templates by family name.
     */
    public function up(): void
    {
        Schema::create('tenant_fonts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // CSS font-family used in template styles; a family has one file per weight and style
            $table->string('family', 60);
            $table->unsignedSmallInteger('weight')->default(400);
            $table->string('style', 10)->default('normal');
            // Detected from the file contents: truetype, opentype, woff or woff2
            $table->string('format', 10);
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->unique(['tenant_id', 'family', 'weight', 'style']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_fonts');
    }
};
