<?php

use App\Support\SensitiveLookup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    public function up(): void
    {
        $changeStorageTypes = DB::connection()->getDriverName() !== 'sqlite';

        $this->dropUniqueIndexIfPresent('users', 'users_email_unique');
        $this->dropUniqueIndexIfPresent('users', 'users_id_number_unique');
        $this->dropUniqueIndexIfPresent('student_references', 'student_references_id_number_unique');

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'name_lookup_hash')) {
                $table->char('name_lookup_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('users', 'email_lookup_hash')) {
                $table->char('email_lookup_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('users', 'id_number_lookup_hash')) {
                $table->char('id_number_lookup_hash', 64)->nullable();
            }
        });
        if ($changeStorageTypes) {
            Schema::table('users', function (Blueprint $table): void {
                $table->longText('name')->change();
                $table->longText('email')->nullable()->change();
                $table->longText('id_number')->nullable()->change();
                $table->text('gender')->nullable()->change();
                $table->text('remember_token')->nullable()->change();
            });
        } elseif (Schema::getColumnType('users', 'gender') !== 'text') {
            Schema::withoutForeignKeyConstraints(function (): void {
                Schema::table('users', function (Blueprint $table): void {
                    $table->text('gender')->nullable()->change();
                });
            });
        }

        Schema::table('student_references', function (Blueprint $table): void {
            if (! Schema::hasColumn('student_references', 'id_number_lookup_hash')) {
                $table->char('id_number_lookup_hash', 64)->nullable();
            }
        });
        if ($changeStorageTypes) {
            Schema::table('student_references', function (Blueprint $table): void {
                $table->longText('id_number')->change();
                $table->longText('name')->change();
                $table->text('gender')->change();
                $table->text('year')->nullable()->change();
                $table->text('course')->nullable()->change();
            });
        }

        if ($changeStorageTypes) {
            Schema::table('account_session_logs', function (Blueprint $table): void {
                $table->text('device_name')->change();
                $table->text('latitude')->nullable()->change();
                $table->text('longitude')->nullable()->change();
            });

            Schema::table('sessions', function (Blueprint $table): void {
                $table->text('ip_address')->nullable()->change();
            });
        }

        $this->encryptRows('users', 'id', [
            'name' => 'name',
            'email' => 'email',
            'id_number' => 'id_number',
            'gender' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'remember_token' => null,
        ]);
        $this->encryptStudentReferences();
        $this->encryptRows('account_session_logs', 'id', [
            'device_name' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
        $this->encryptSessionRows();
        $this->encryptRows('notifications', 'id', ['data' => null]);
        $this->hashPasswordResetEmails();
        $this->encryptCacheRows();

        $this->addIndexIfMissing('users', ['name_lookup_hash'], 'users_name_lookup_hash_index');
        $this->addUniqueIndexIfMissing('users', ['email_lookup_hash'], 'users_email_lookup_hash_unique');
        $this->addUniqueIndexIfMissing('users', ['id_number_lookup_hash'], 'users_id_number_lookup_hash_unique');
        $this->addUniqueIndexIfMissing('student_references', ['id_number_lookup_hash'], 'student_references_id_number_lookup_hash_unique');
    }

    public function down(): void
    {
        throw new RuntimeException('Sensitive identity encryption is irreversible. Restore a backup to roll back this migration.');
    }

    /**
     * @param  array<string, string|null>  $lookupFields
     */
    private function encryptRows(string $table, string $keyColumn, array $lookupFields): void
    {
        DB::table($table)
            ->orderBy($keyColumn)
            ->chunkById(100, function ($rows) use ($table, $keyColumn, $lookupFields): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($lookupFields as $field => $lookupField) {
                        $value = $row->{$field};

                        if ($value === null) {
                            if ($lookupField !== null) {
                                $updates[$field.'_lookup_hash'] = null;
                            }

                            continue;
                        }

                        [$plainText, $alreadyEncrypted] = $this->plainText((string) $value);
                        $updates[$field] = $alreadyEncrypted ? $value : Crypt::encryptString($plainText);

                        if ($lookupField !== null) {
                            $updates[$field.'_lookup_hash'] = SensitiveLookup::hash($lookupField, $plainText);
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where($keyColumn, $row->{$keyColumn})->update($updates);
                    }
                }
            }, $keyColumn);
    }

    private function encryptStudentReferences(): void
    {
        DB::table('student_references')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    [$idNumber, $idEncrypted] = $this->plainText((string) $row->id_number);
                    $updates = [
                        'id_number' => $idEncrypted ? $row->id_number : Crypt::encryptString($idNumber),
                        'id_number_lookup_hash' => SensitiveLookup::hash('id_number', $idNumber),
                    ];

                    foreach (['name', 'gender', 'year', 'course'] as $field) {
                        if ($row->{$field} === null) {
                            continue;
                        }

                        [$plainText, $alreadyEncrypted] = $this->plainText((string) $row->{$field});
                        $updates[$field] = $alreadyEncrypted ? $row->{$field} : Crypt::encryptString($plainText);
                    }

                    if ($row->source_data !== null) {
                        $source = json_decode($row->source_data, true, flags: JSON_THROW_ON_ERROR);

                        if (is_string($source)) {
                            $source = $this->decrypt($source) ?? json_encode($source, JSON_THROW_ON_ERROR);
                        }

                        $plainSource = is_string($source) ? $source : json_encode($source, JSON_THROW_ON_ERROR);
                        $updates['source_data'] = json_encode(Crypt::encryptString($plainSource), JSON_THROW_ON_ERROR);
                    }

                    DB::table('student_references')->where('id', $row->id)->update($updates);
                }
            });
    }

    private function encryptSessionRows(): void
    {
        $sessionIds = DB::table('sessions')->orderBy('id')->pluck('id');

        foreach ($sessionIds->chunk(100) as $sessionIdChunk) {
            $rows = DB::table('sessions')->whereIn('id', $sessionIdChunk)->get();

            foreach ($rows as $row) {
                $sessionId = preg_match('/^[a-f0-9]{64}$/D', $row->id)
                    ? $row->id
                    : SensitiveLookup::hash('session_id', $row->id)
                        ?? throw new RuntimeException('A session ID hash could not be generated.');
                $payload = base64_decode($row->payload, true);

                if ($payload === false) {
                    throw new RuntimeException('A stored session payload could not be decoded during encryption.');
                }

                if ($this->decrypt($payload) === null) {
                    $payload = Crypt::encryptString($payload);
                }

                $updates = [
                    'id' => $sessionId,
                    'payload' => base64_encode($payload),
                ];

                foreach (['ip_address', 'user_agent'] as $field) {
                    if ($row->{$field} !== null && $this->decrypt($row->{$field}) === null) {
                        $updates[$field] = Crypt::encryptString($row->{$field});
                    }
                }

                DB::table('sessions')->where('id', $row->id)->update($updates);
            }
        }
    }

    private function hashPasswordResetEmails(): void
    {
        foreach (DB::table('password_reset_tokens')->pluck('email') as $email) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $email)) {
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->update(['email' => SensitiveLookup::hash('email', $email)]);
            }
        }
    }

    private function encryptCacheRows(): void
    {
        DB::table('cache')->orderBy('key')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                if ($this->decrypt($row->value) === null) {
                    DB::table('cache')->where('key', $row->key)->update([
                        'value' => Crypt::encryptString($row->value),
                    ]);
                }
            }
        }, 'key');
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function plainText(string $value): array
    {
        $decrypted = $this->decrypt($value);

        return $decrypted === null ? [$value, false] : [$decrypted, true];
    }

    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    private function dropUniqueIndexIfPresent(string $table, string $name): void
    {
        if (collect(Schema::getIndexes($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($name));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUniqueIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (! collect(Schema::getIndexes($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (! collect(Schema::getIndexes($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }
};
