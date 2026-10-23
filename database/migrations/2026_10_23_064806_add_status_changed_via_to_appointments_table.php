<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which way an appointment's last step was taken (5.6e — an AppointmentSource: staff, the person themself on the
     * public link, or another system through the API), so one taken by no user still says who took it. Steps
     * taken so far: by a user — staff; by no one — the person on the public link (the only such way until now).
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('status_changed_via', 16)->nullable()->after('status_changed_by_id');
        });
        DB::table('appointments')->whereNotNull('status_changed_at')->update([
            'status_changed_via' => DB::raw("case when status_changed_by_id is null then 'public' else 'staff' end"),
        ]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('status_changed_via');
        });
    }
};
