<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            // A deleted original must not take the delivery that sent it again with it
            $table->foreignId('resend_of_id')->nullable()->after('webhook_id')->constrained('webhook_deliveries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resend_of_id');
        });
    }
};
