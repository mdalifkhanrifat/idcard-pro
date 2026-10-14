<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every message the application sends (5.2): to whom, on which channel, about what, the words as sent,
     * and how it went. The table is itself the log, so it is not written to the activity log as well.
     * Recipients and what a message is about are plain foreign keys (no class names stored): a link turns
     * empty when its record is deleted, the message stays (old messages leave only when the organization
     * chose so, app:prune-notification-log). A new kind of subject (e.g. appointments) is a new column.
     */
    public function up(): void
    {
        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // config('notifications.events') key, or "test" for a test message
            $table->string('event', 40);
            // config('notifications.channels') key
            $table->string('channel', 20);
            // The e-mail address or phone number it went to, as it was then
            $table->string('address')->nullable();
            // Whom it went to (at most one)
            $table->foreignId('cardholder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // What it is about
            $table->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            // Languages it was written in, in order, e.g. "bn,en"
            $table->string('languages', 20);
            $table->string('subject')->nullable();
            $table->text('body');
            // queued, sent, failed, skipped
            $table->string('status', 12)->default('queued');
            // Why it was skipped (e.g. no_address), as a code
            $table->string('reason', 40)->nullable();
            // What the channel answered when it failed
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // The channel's driver that sent it (e.g. smtp, log) and the provider's own id for it
            $table->string('driver', 40)->nullable();
            $table->string('provider_id')->nullable();
            // Who sent it by hand; empty = sent by the system on an event
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_messages');
    }
};
