<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Biometric captures (Phase 4): one row per capture of a position (a finger, the face, an eye) of a
     * cardholder or one of their family members, or a note that it cannot be captured (e.g. a missing
     * finger). Image and template are encrypted files (BiometricVault); the row keeps only what is
     * needed to list and check them.
     */
    public function up(): void
    {
        Schema::create('biometric_captures', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cardholder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained()->cascadeOnDelete();
            // config/biometrics.php: modality and position
            $table->string('modality', 30);
            $table->string('position', 40);
            // captured, or unavailable with a reason
            $table->string('status', 20);
            $table->string('unavailable_reason', 30)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('driver', 30)->nullable();
            // Scanner details the driver reports: vendor, model, serial
            $table->json('device')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('image_path')->nullable()->unique();
            $table->string('image_format', 20)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->unsignedSmallInteger('image_dpi')->nullable();
            $table->string('template_path')->nullable()->unique();
            $table->string('template_format', 30)->nullable();
            // 0-100 as the driver measured it; null when unknown (e.g. an uploaded image)
            $table->unsignedTinyInteger('quality')->nullable();
            // Kept below the organization's minimum after the allowed tries
            $table->boolean('low_quality')->default(false);
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            // Set when a newer capture of the same position replaces this one (kept as history)
            $table->timestamp('replaced_at')->nullable();
            // "{holder}:{modality}:{position}" while current, null once replaced: one current capture per position
            $table->string('current_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['cardholder_id', 'family_member_id', 'replaced_at'], 'biometric_captures_holder_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_captures');
    }
};
