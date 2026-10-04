<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: templates (template_type, title, code, template_structure,
     * card_width, card_height, h_w_extention, status, authority_type, user_id).
     */
    public function up(): void
    {
        Schema::create('card_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Card type code; also the {CODE} of the card number format
            $table->string('code', 20);
            $table->foreignId('card_type_id')->nullable()->constrained('master_data_items')->restrictOnDelete();
            $table->string('audience', 20)->default('cardholder');
            $table->string('orientation', 20)->default('horizontal');
            $table->decimal('width', 7, 2);
            $table->decimal('height', 7, 2);
            $table->string('size_unit', 2)->default('mm');
            // The active signatory of this authority type signs the card (legacy behaviour)
            $table->foreignId('authority_type_id')->nullable()->constrained('master_data_items')->restrictOnDelete();
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->boolean('is_double_sided')->default(true);
            $table->boolean('is_active')->default(true);
            // Raised whenever the design changes, so issued cards keep the version they were printed with
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_templates');
    }
};
