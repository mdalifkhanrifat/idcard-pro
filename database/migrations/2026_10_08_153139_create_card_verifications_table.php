<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every public check of a card (3.7), so the organization sees how often and when a card was verified,
     * and notices checks of revoked or replaced cards. No IP address is kept: `device` is a keyed hash of it,
     * enough to tell devices apart. Old checks are pruned (config cards.verification.log_days).
     */
    public function up(): void
    {
        Schema::create('card_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // The card's status at the time: valid, expired, revoked, replaced
            $table->string('status', 12);
            $table->char('device', 16);
            $table->timestamp('verified_at');

            $table->index(['card_id', 'verified_at']);
            $table->index(['tenant_id', 'verified_at']);
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->unsignedInteger('verify_count')->default(0)->after('last_printed_at');
            $table->timestamp('last_verified_at')->nullable()->after('verify_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['verify_count', 'last_verified_at']);
        });
        Schema::dropIfExists('card_verifications');
    }
};
