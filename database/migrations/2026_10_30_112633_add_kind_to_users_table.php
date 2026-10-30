<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a user row is: a person, or an account another system works through (6.1). Such an account has no e-mail
     * of its own, so the address becomes optional; the sign-in lookup only ever reads people.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kind', 20)->default('person')->after('is_platform_admin')->index();
            $table->string('description', 500)->nullable()->after('name');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropColumn(['kind', 'description']);
        });
    }
};
