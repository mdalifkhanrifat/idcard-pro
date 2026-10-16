<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days no appointment is taken (5.3c): a holiday or a closure of one place, or of every place (no place),
     * from one day to another (both whole, the organization's days); a yearly one comes back every year on the
     * same dates (a fixed-date holiday). New (the legacy system had no appointments).
     */
    public function up(): void
    {
        Schema::create('appointment_closures', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // null: every place
            $table->foreignId('location_id')->nullable()->constrained('appointment_locations')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->boolean('yearly')->default(false);
            $table->string('reason', 200)->nullable();
            $table->string('reason_local', 200)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_closures');
    }
};
