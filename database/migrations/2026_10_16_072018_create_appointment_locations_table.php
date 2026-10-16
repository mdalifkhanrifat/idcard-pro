<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Places people come to for an appointment (5.3b): a branch, an office or a counter, with its own weekly hours,
     * how many are served at once and per day, and the kinds of visit it takes. New (the legacy system had none).
     */
    public function up(): void
    {
        Schema::create('appointment_locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('name_local', 120)->nullable();
            $table->string('address', 500)->nullable();
            // People served at once in one time slot (counters side by side), and in one day (day scheduling)
            $table->unsignedSmallInteger('slot_capacity')->default(1);
            $table->unsignedSmallInteger('day_capacity')->default(50);
            // Kinds of visit it takes (keys of the organization's settings); null: every kind
            $table->json('services')->nullable();
            // Weekly opening hours: {mon: [["09:00", "13:00"], ["14:00", "17:00"]], ..., sun: []}
            $table->json('hours');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_locations');
    }
};
