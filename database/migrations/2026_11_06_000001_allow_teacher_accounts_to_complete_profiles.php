<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('id_number')->nullable()->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ALTER COLUMN "gender" DROP NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('gender', ['male', 'female'])->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->string('id_number')->nullable(false)->change();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "users" ALTER COLUMN "gender" SET NOT NULL');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('gender', ['male', 'female'])->nullable(false)->change();
            });
        }
    }
};
