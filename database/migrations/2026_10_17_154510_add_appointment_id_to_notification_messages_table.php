<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message about an appointment (5.4e: booked, changed, cancelled, reminder) points to it, as a card's does
     * to the card. Appointments are never deleted; were one deleted, its messages would stay, unlinked.
     */
    public function up(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('card_id')->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};
