<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: card_information (card_type = template, card_id_number, rf_card_no,
     * card_issue_date, card_expair_date; one row per person, overwritten) and card_print_history
     * (card_get = reason, notes). Now every issue is a row of its own and keeps what it was made
     * from (template version, layout, photo, signatures), so a card always reprints the same.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The holder: a cardholder, or one of their family members (family card)
            $table->foreignId('cardholder_id')->constrained()->restrictOnDelete();
            $table->foreignId('family_member_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('card_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('template_version');
            // Snapshot of the design at issue: card size, sides and elements
            $table->json('layout');
            // Not unique on its own: a reissue may keep the number (settings); see active_number_key
            $table->string('number', 60);
            $table->string('rf_card_no', 60)->nullable();
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->foreignId('document_file_id')->nullable()->constrained('cardholder_files')->nullOnDelete();
            // What was printed on it
            $table->foreignId('photo_file_id')->nullable()->constrained('cardholder_files')->restrictOnDelete();
            $table->foreignId('signature_file_id')->nullable()->constrained('cardholder_files')->restrictOnDelete();
            $table->foreignId('signatory_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('status_changed_at')->nullable();
            $table->string('status_note')->nullable();
            $table->foreignId('replaced_by_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            // Set only while the card is active, so the database allows one active card per number and per holder
            $table->string('active_number_key', 60)->nullable();
            $table->string('active_holder_key', 40)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'active_number_key']);
            $table->unique(['tenant_id', 'active_holder_key']);
            $table->index(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'expiry_date']);
            $table->index(['tenant_id', 'rf_card_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
