<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reminders sent before appointments (5.7c): one per appointment, hours before and the moment it was due — so
     * each goes once, even with two runs at the same time (the unique key), and a moved appointment is reminded
     * again for its new time. Kept apart from the message log, which an organization may have deleted.
     */
    public function up(): void
    {
        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('hours');
            // UTC: the appointment's start less the hours, when it was sent
            $table->dateTime('due_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['appointment_id', 'hours', 'due_at'], 'appointment_reminders_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
    }
};
