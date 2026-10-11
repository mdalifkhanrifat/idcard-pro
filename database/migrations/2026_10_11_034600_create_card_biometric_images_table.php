<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biometric images an issued card prints (4.10), kept when the organization prints the image captured
     * at issue (biometrics.print_source = issue): the card's own encrypted copy (BiometricVault), image only,
     * so a later capture does not change the card. One row per biometric element field of the card's design;
     * a row without a file means the person had nothing printable then, so the card prints the fallback.
     * A card without rows was issued while the organization printed the latest capture.
     */
    public function up(): void
    {
        Schema::create('card_biometric_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // The design element's field: biometric.<modality>.<position|auto>
            $table->string('field', 80);
            // What was copied: the capture's modality and position (for `auto`, the one chosen)
            $table->string('modality', 30);
            $table->string('position', 40)->nullable();
            $table->foreignId('biometric_capture_id')->nullable()->constrained()->nullOnDelete();
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable()->unique();
            $table->string('image_format', 20)->nullable();
            $table->timestamps();

            $table->unique(['card_id', 'field'], 'card_biometric_images_card_field_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_biometric_images');
    }
};
