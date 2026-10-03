<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cardholder_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 20);
            $table->string('name');
            $table->string('name_local')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('blood_group_id')->nullable()->constrained('master_data_items')->restrictOnDelete();
            $table->string('national_id', 40)->nullable();
            $table->string('passport_no', 40)->nullable();
            $table->string('identification_mark')->nullable();
            // Reference number of a family card issued outside this system (legacy "card_no")
            $table->string('card_no', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['cardholder_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
