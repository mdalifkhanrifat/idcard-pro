<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_data_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignId('parent_id')->nullable()->constrained('master_data_items')->restrictOnDelete();
            $table->string('name');
            $table->string('name_local')->nullable();
            $table->string('code', 32)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'is_active']);
            // Same name may repeat under different parents (e.g. "Sadar" exists in many districts).
            $table->unique(['tenant_id', 'type', 'parent_id', 'name']);
            $table->unique(['tenant_id', 'type', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_data_items');
    }
};
