<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            // The exact text sent: a JSON column would reorder its keys in MySQL
            $table->longText('payload');
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('failure', 30)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
