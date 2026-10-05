<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * New (no legacy table): the organization's image library. Logos and backgrounds are uploaded
     * once and linked from any card template. Files never change; to change a logo everywhere a new
     * image replaces the old one in every template.
     */
    public function up(): void
    {
        Schema::create('tenant_images', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Re-encoded by ImageSanitizer, on the private disk
            $table->string('disk', 40);
            $table->string('path');
            $table->string('mime_type', 40);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_images');
    }
};
