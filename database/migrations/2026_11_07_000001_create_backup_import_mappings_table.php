<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('backup_id');
            $table->string('table_name', 64);
            $table->unsignedBigInteger('source_record_id');
            $table->unsignedBigInteger('target_record_id');
            $table->unique(['backup_id', 'table_name', 'source_record_id'], 'backup_import_mappings_source_unique');
            $table->index(['table_name', 'target_record_id'], 'backup_import_mappings_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_import_mappings');
    }
};
