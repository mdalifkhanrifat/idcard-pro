<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether each person meets the organization's biometric settings (App\Enums\BiometricStatus), kept up
 * to date when captures, people or settings change: for list filters, card issue and print checks,
 * reports and connected systems without working it out per row. Existing rows: `app:biometric-status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cardholders', function (Blueprint $table) {
            $table->string('biometric_status', 20)->default('not_required')->after('status');
            $table->index(['tenant_id', 'biometric_status'], 'cardholders_tenant_biometric_status_index');
        });
        Schema::table('family_members', function (Blueprint $table) {
            $table->string('biometric_status', 20)->default('not_required');
        });
    }

    public function down(): void
    {
        Schema::table('cardholders', function (Blueprint $table) {
            $table->dropIndex('cardholders_tenant_biometric_status_index');
            $table->dropColumn('biometric_status');
        });
        Schema::table('family_members', function (Blueprint $table) {
            $table->dropColumn('biometric_status');
        });
    }
};
