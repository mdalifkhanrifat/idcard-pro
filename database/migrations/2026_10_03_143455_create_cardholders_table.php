<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master data references kept on a cardholder.
     */
    private const LOOKUPS = [
        'blood_group_id', 'religion_id', 'region_id', 'sub_region_id',
        'department_id', 'unit_id', 'rank_id', 'designation_id', 'branch_id', 'trade_id', 'appointment_id',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cardholders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('full_name');
            $table->string('full_name_local')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('marital_status', 10)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('national_id', 40)->nullable();
            $table->string('passport_no', 40)->nullable();

            // Contact
            $table->string('email')->nullable();
            $table->string('personal_phone', 30)->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('postal_code', 20)->nullable();

            // Physical description
            $table->string('height', 20)->nullable();
            $table->string('weight', 20)->nullable();
            $table->string('eye_color', 30)->nullable();
            $table->string('complexion', 30)->nullable();
            $table->string('identification_mark')->nullable();

            // Office
            $table->string('employee_no', 40)->nullable();
            $table->string('secondary_no', 40)->nullable();
            $table->string('employment_type', 20)->default('staff');
            $table->date('joining_date')->nullable();
            $table->date('retirement_date')->nullable();
            $table->string('retired_id_no', 40)->nullable();
            $table->string('posting')->nullable();
            $table->string('previous_office')->nullable();

            // External organization (contractors, visitors)
            $table->string('company_name')->nullable();
            $table->string('company_address')->nullable();
            $table->string('company_phone', 30)->nullable();

            // Master data references; items in use cannot be deleted
            foreach (self::LOOKUPS as $column) {
                $table->foreignId($column)->nullable()->constrained('master_data_items')->restrictOnDelete();
            }

            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_no']);
            $table->unique(['tenant_id', 'national_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'full_name']);
            $table->index(['tenant_id', 'joining_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cardholders');
    }
};
