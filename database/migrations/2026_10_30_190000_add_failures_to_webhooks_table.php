<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->unsignedInteger('failure_count')->default(0)->after('enabled');
            // When it turned itself off; null when on, or turned off by a person
            $table->timestamp('disabled_at')->nullable()->after('failure_count');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn(['failure_count', 'disabled_at']);
        });
    }
};
