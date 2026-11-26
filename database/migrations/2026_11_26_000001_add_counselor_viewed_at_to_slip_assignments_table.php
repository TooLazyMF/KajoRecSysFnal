<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->timestamp('counselor_viewed_at')->nullable()->after('student_sent_to_teacher_at');
        });
    }

    public function down(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->dropColumn('counselor_viewed_at');
        });
    }
};
