<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every time an issued card is printed (legacy card_print_history, which only logged the issue).
     * A card can be printed again (e.g. after a printer jam) without a new number or a new card.
     * The card keeps a running total so lists can show "printed / not printed yet" without counting.
     */
    public function up(): void
    {
        Schema::create('card_prints', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            // Cards printed together share a batch id
            $table->ulid('batch')->nullable()->index();
            // config('cards.print.methods'): browser, pdf, ...
            $table->string('method', 20);
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('note')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->index(['card_id', 'printed_at']);
            $table->index(['tenant_id', 'printed_at']);
        });

        Schema::table('cards', function (Blueprint $table) {
            // Copies printed so far; 0 = issued but not printed yet (legacy "PENDING")
            $table->unsignedInteger('print_count')->default(0)->after('issued_by');
            $table->timestamp('last_printed_at')->nullable()->after('print_count');
            $table->index(['tenant_id', 'last_printed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'last_printed_at']);
            $table->dropColumn(['print_count', 'last_printed_at']);
        });
        Schema::dropIfExists('card_prints');
    }
};
