<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->text('guidance_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->dropColumn('guidance_notes');
        });
    }
};
