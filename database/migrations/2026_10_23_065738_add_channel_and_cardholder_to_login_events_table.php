<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which way someone signed in (5.6e — a LoginChannel: staff, or a cardholder on the public booking link) and, for
     * a cardholder, who: so the audit's sign-ins show the public link's proven sign-ins and lock-outs too. Every
     * event so far was a staff sign-in.
     */
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->string('channel', 32)->default('staff')->after('tenant_id')->index();
            $table->foreignId('cardholder_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cardholder_id');
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
        });
    }
};
