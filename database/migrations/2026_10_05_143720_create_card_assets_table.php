<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Shared things an issued card's design uses (library images by id, fonts by family), written at
     * issue so they cannot be deleted while a card needs them. Searching the layout JSON instead would
     * behave differently per database. New kinds can be added without schema changes.
     */
    public function up(): void
    {
        Schema::create('card_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // library_image (reference = tenant_images.id) or font (reference = family name)
            $table->string('kind', 20);
            $table->string('reference', 100);

            $table->unique(['card_id', 'kind', 'reference']);
            $table->index(['tenant_id', 'kind', 'reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_assets');
    }
};
