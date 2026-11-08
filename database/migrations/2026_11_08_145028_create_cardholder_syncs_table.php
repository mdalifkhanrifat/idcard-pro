<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One run of an HR sync (POST /api/v1/sync/cardholders): who sent it, when, and what came of it.
     * Never what was sent: the people's data stays in the cardholders and the audit log.
     */
    public function up(): void
    {
        Schema::create('cardholder_syncs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The integration account (or person) that sent it
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at');
            // Null: the run broke off (the records saved until then stay)
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cardholder_syncs');
    }
};
