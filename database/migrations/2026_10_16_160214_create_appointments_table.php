<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments (5.4): a cardholder (or a family member of theirs) booked at a place for a kind of visit, on
     * a day (the organization's) and — when times are booked — from one moment to another (UTC). A short number
     * of the organization's own format, a status, where the booking came from and who made it. Never deleted
     * (cancelled instead), so the place, the cardholder and the family member are kept while bookings exist.
     * New (the legacy system's `appointments` table is a list of posts, now master data).
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('location_id')->constrained('appointment_locations')->restrictOnDelete();
            // A kind of visit's key (the registry's or the organization's own)
            $table->string('service', 40);
            $table->foreignId('cardholder_id')->constrained()->restrictOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            // Booked times (UTC); null when a day is booked (served in turn)
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 20);
            $table->string('source', 20);
            $table->foreignId('booked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'date']);
            $table->index(['location_id', 'date', 'status']);
            $table->index(['cardholder_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
