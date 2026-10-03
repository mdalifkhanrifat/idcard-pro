<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Legacy mapping: education_infos (exam, board, passing_year, group, degree, gpa) and
     * other_courses (course_name, institute_name, course_year, course_result, cirtificate_number).
     */
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cardholder_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title');
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('subject')->nullable();
            $table->string('degree')->nullable();
            $table->string('result', 40)->nullable();
            $table->string('certificate_no', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['cardholder_id', 'kind', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qualifications');
    }
};
