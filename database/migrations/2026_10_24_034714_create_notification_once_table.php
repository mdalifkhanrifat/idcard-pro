<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled messages already sent (5.7d), for any event that goes on its own time rather than on a change —
     * first the reminders before a card's last day: the event, what it is about (its id, of the kind the event
     * names) and a key saying which one (e.g. "30:2026-12-31" — 30 days before that last day). The unique key makes
     * each go once, even with two runs at the same time; kept apart from the message log, which an organization may
     * have deleted. (Appointment reminders keep their own table, appointment_reminders, from 5.7c.)
     */
    public function up(): void
    {
        Schema::create('notification_once', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('key', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event', 'subject_id', 'key'], 'notification_once_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_once');
    }
};
