<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_account_requests', function (Blueprint $table): void {
            $table->id();
            $table->longText('name');
            $table->char('name_lookup_hash', 64)->index();
            $table->longText('email');
            $table->char('email_lookup_hash', 64)->unique();
            $table->longText('id_number');
            $table->char('id_number_lookup_hash', 64)->index();
            $table->string('password')->nullable();
            $table->string('id_photo_path')->nullable();
            $table->string('id_photo_mime_type')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->text('decision_message')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_account_requests');
    }
};
