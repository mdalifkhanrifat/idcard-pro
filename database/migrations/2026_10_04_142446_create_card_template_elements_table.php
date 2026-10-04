<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: template_sections (card_type = side, section_type = type, field_value,
     * relation_name, label, section_position/width/height, font and colour columns).
     */
    public function up(): void
    {
        Schema::create('card_template_elements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_template_id')->constrained()->cascadeOnDelete();
            $table->string('side', 5);
            $table->string('type', 30);
            // Data field for "field" elements, e.g. full_name, custom.class, card.number
            $table->string('field', 60)->nullable();
            $table->string('label')->nullable();
            // Static text for "text" elements
            $table->text('content')->nullable();
            // Uploaded image for logo and background elements (private disk)
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path')->nullable();
            // Position and size in millimetres from the top-left corner of the side
            $table->decimal('x', 7, 2);
            $table->decimal('y', 7, 2);
            $table->decimal('width', 7, 2);
            $table->decimal('height', 7, 2);
            $table->unsignedSmallInteger('z_index')->default(0);
            // font_family, font_size (pt), color, background, bold, italic, underline, align, label_color
            $table->json('style')->nullable();
            $table->timestamps();

            $table->index(['card_template_id', 'side', 'z_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_template_elements');
    }
};
