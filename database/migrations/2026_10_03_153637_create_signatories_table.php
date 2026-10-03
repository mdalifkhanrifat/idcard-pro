<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: authorized_signatures (name, designation, signature, status).
     */
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_local')->nullable();
            $table->string('designation');
            $table->string('designation_local')->nullable();
            $table->foreignId('authority_type_id')->nullable()->constrained('master_data_items')->restrictOnDelete();
            // Signature image on the private disk (re-encoded like cardholder signatures)
            $table->string('signature_disk', 40)->nullable();
            $table->string('signature_path')->nullable();
            // Issued cards keep the signatory that was valid on their issue date
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatories');
    }
};
