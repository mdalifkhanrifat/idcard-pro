<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message sent again by hand (5.2d-3) is a new row that points to the one it repeats; the old one stays as
     * history. Deleting the old one (the organization's log pruning) leaves the new one, unlinked.
     */
    public function up(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->foreignId('resend_of_id')->nullable()->after('sent_by')->constrained('notification_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notification_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resend_of_id');
        });
    }
};
