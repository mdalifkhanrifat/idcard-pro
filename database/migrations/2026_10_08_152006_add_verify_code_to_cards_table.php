<?php

use App\Models\Card;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The code in a card's verification link (3.7): random and unguessable, fixed at issue so every reprint
     * carries the same link; already issued cards get one now.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('verify_code', 32)->nullable()->unique()->after('number');
        });

        DB::table('cards')->whereNull('verify_code')->orderBy('id')->lazyById()->each(function (object $card): void {
            DB::table('cards')->where('id', $card->id)->update(['verify_code' => Card::newVerifyCode()]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropUnique(['verify_code']);
            $table->dropColumn('verify_code');
        });
    }
};
