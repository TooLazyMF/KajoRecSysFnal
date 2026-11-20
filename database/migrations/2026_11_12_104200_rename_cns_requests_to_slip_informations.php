<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->dropForeign(['cns_request_id']);
        });

        Schema::rename('cns_requests', 'slip_informations');

        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->renameColumn('cns_request_id', 'slip_information_id');
            $table->foreign('slip_information_id')
                ->references('id')
                ->on('slip_informations')
                ->nullOnDelete();
        });

        DB::table('backup_import_mappings')
            ->where('table_name', 'cns_requests')
            ->update(['table_name' => 'slip_informations']);
    }

    public function down(): void
    {
        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->dropForeign(['slip_information_id']);
            $table->renameColumn('slip_information_id', 'cns_request_id');
        });

        Schema::rename('slip_informations', 'cns_requests');

        Schema::table('slip_assignments', function (Blueprint $table): void {
            $table->foreign('cns_request_id')
                ->references('id')
                ->on('cns_requests')
                ->nullOnDelete();
        });

        DB::table('backup_import_mappings')
            ->where('table_name', 'slip_informations')
            ->update(['table_name' => 'cns_requests']);
    }
};
