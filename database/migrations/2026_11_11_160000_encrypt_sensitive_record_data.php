<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CNS_FIELDS = [
        'full_name',
        'last_name',
        'first_name',
        'middle_initial',
        'student_id',
        'email',
        'phone',
        'degree',
        'year_level',
        'school_year',
        'purpose',
        'purpose_other',
    ];

    private const APPOINTMENT_FIELDS = [
        'purpose',
        'concerns',
        'decline_reason',
        'guidance_message',
        'reschedule_reason',
        'action_taken',
    ];

    public function up(): void
    {
        Schema::table('cns_requests', function (Blueprint $table): void {
            $table->text('full_name')->change();
            $table->text('last_name')->nullable()->change();
            $table->text('first_name')->nullable()->change();
            $table->text('middle_initial')->nullable()->change();
            $table->text('student_id')->change();
            $table->text('email')->change();
            $table->text('phone')->nullable()->change();
            $table->text('degree')->change();
            $table->text('year_level')->change();
            $table->text('school_year')->change();
            $table->text('purpose')->change();
            $table->text('purpose_other')->nullable()->change();
        });

        Schema::table('slip_submissions', function (Blueprint $table): void {
            $table->longText('data')->change();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->longText('attachments')->nullable()->change();
        });

        $this->transformRows('cns_requests', self::CNS_FIELDS, encrypt: true);
        $this->transformRows('slip_submissions', ['data'], encrypt: true);
        $this->transformRows('slip_assignments', ['notes'], encrypt: true);
        $this->transformRows('appointments', self::APPOINTMENT_FIELDS, encrypt: true);
        $this->transformRows('appointments', ['attachments'], encrypt: true, json: true);
    }

    public function down(): void
    {
        $this->transformRows('cns_requests', self::CNS_FIELDS, encrypt: false);
        $this->transformRows('slip_submissions', ['data'], encrypt: false);
        $this->transformRows('slip_assignments', ['notes'], encrypt: false);
        $this->transformRows('appointments', self::APPOINTMENT_FIELDS, encrypt: false);
        $this->transformRows('appointments', ['attachments'], encrypt: false, json: true);

        Schema::table('slip_submissions', function (Blueprint $table): void {
            $table->json('data')->change();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->json('attachments')->nullable()->change();
        });

        Schema::table('cns_requests', function (Blueprint $table): void {
            $table->string('full_name')->change();
            $table->string('last_name', 80)->nullable()->change();
            $table->string('first_name', 80)->nullable()->change();
            $table->string('middle_initial', 10)->nullable()->change();
            $table->string('student_id', 50)->change();
            $table->string('email')->change();
            $table->string('phone', 30)->nullable()->change();
            $table->string('degree', 20)->change();
            $table->string('year_level', 20)->change();
            $table->string('school_year', 50)->change();
            $table->string('purpose', 40)->change();
            $table->string('purpose_other')->nullable()->change();
        });
    }

    /**
     * @param  list<string>  $fields
     */
    private function transformRows(string $table, array $fields, bool $encrypt, bool $json = false): void
    {
        DB::table($table)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $fields, $encrypt, $json): void {
                foreach ($rows as $row) {
                    $values = [];

                    foreach ($fields as $field) {
                        if ($row->{$field} === null) {
                            continue;
                        }

                        if ($encrypt) {
                            if ($this->decryptIfEncrypted($row->{$field}, $json) !== null) {
                                continue;
                            }

                            $encrypted = Crypt::encryptString($json
                                ? json_encode(json_decode($row->{$field}, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)
                                : $row->{$field});
                            $values[$field] = $json
                                ? json_encode($encrypted, JSON_THROW_ON_ERROR)
                                : $encrypted;
                        } else {
                            $decrypted = $this->decryptIfEncrypted($row->{$field}, $json);

                            if ($decrypted === null) {
                                continue;
                            }

                            $values[$field] = $json
                                ? json_encode(json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)
                                : $decrypted;
                        }
                    }

                    if ($values !== []) {
                        DB::table($table)->where('id', $row->id)->update($values);
                    }
                }
            });
    }

    private function decryptIfEncrypted(string $value, bool $json = false): ?string
    {
        try {
            $decrypted = Crypt::decryptString($value);

            return $json
                ? json_encode(json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)
                : $decrypted;
        } catch (DecryptException) {
            if ($json) {
                try {
                    $wrapped = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

                    if (is_string($wrapped)) {
                        $decrypted = Crypt::decryptString($wrapped);

                        return json_encode(json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
                    }
                } catch (DecryptException|JsonException) {
                    return null;
                }
            }

            return null;
        } catch (JsonException) {
            return null;
        }
    }
};
