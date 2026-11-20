<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DatabaseNotification;
use App\Models\SlipAssignment;
use App\Models\SlipInformation;
use App\Models\SlipSubmission;
use App\Models\StudentReference;
use App\Models\User;
use App\Session\EncryptedDatabaseSessionHandler;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensitiveRecordEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_record_content_is_encrypted_in_storage_and_readable_through_models(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $storedUser = DB::table('users')->where('id', $user->id)->first();

        $this->assertSame($user->name, Crypt::decryptString($storedUser->name));
        $this->assertSame($user->email, Crypt::decryptString($storedUser->email));
        $this->assertSame($user->id_number, Crypt::decryptString($storedUser->id_number));
        $this->assertNotSame($user->email, $storedUser->email);
        $this->assertSame(SensitiveLookup::hash('email', $user->email), $storedUser->email_lookup_hash);
        $this->assertSame(SensitiveLookup::hash('id_number', $user->id_number), $storedUser->id_number_lookup_hash);

        $request = SlipInformation::create([
            'user_id' => $user->id,
            'full_name' => 'Ada Lovelace',
            'last_name' => 'Lovelace',
            'first_name' => 'Ada',
            'middle_initial' => 'B',
            'student_id' => 'CNS-123',
            'email' => 'ada@example.test',
            'phone' => '09123456789',
            'degree' => 'BSCS',
            'year_level' => '3',
            'school_year' => '2026-2027',
            'purpose' => 'Transcript',
            'purpose_other' => 'Personal reason',
            'pdf_path' => 'cns/example.pdf',
        ]);
        $submission = SlipSubmission::create([
            'user_id' => $user->id,
            'slip' => 'client-information-form',
            'data' => ['student_name' => 'Ada Lovelace', 'personal_concern' => 'Private detail'],
            'pdf_path' => 'slips/example.pdf',
        ]);
        $assignment = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $user->id,
            'slip' => 'student-referral-slip',
            'notes' => 'Private referral note',
        ]);
        $appointment = Appointment::create([
            'user_id' => $user->id,
            'scheduled_at' => now()->addDay(),
            'purpose' => 'Private appointment purpose',
            'action_taken' => 'Private action taken',
            'attachments' => [['name' => 'private-document.pdf', 'path' => 'appointments/private-document.pdf']],
        ]);

        $storedRequest = DB::table('slip_informations')->where('id', $request->id)->first();
        $this->assertSame('Ada Lovelace', Crypt::decryptString($storedRequest->full_name));
        $this->assertSame('ada@example.test', Crypt::decryptString($storedRequest->email));
        $this->assertSame('Ada Lovelace', $request->fresh()->full_name);

        $storedSubmission = DB::table('slip_submissions')->where('id', $submission->id)->value('data');
        $this->assertStringNotContainsString('Private detail', $storedSubmission);
        $this->assertSame('Private detail', $submission->fresh()->data['personal_concern']);

        $storedAssignment = DB::table('slip_assignments')->where('id', $assignment->id)->value('notes');
        $this->assertStringNotContainsString('Private referral note', $storedAssignment);
        $this->assertSame('Private referral note', $assignment->fresh()->notes);

        $storedAppointment = DB::table('appointments')->where('id', $appointment->id)->first();
        $this->assertSame('Private appointment purpose', Crypt::decryptString($storedAppointment->purpose));
        $this->assertSame('Private action taken', Crypt::decryptString($storedAppointment->action_taken));
        $this->assertSame('Private action taken', $appointment->fresh()->action_taken);
        $storedAttachmentCiphertext = json_decode($storedAppointment->attachments, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsString($storedAttachmentCiphertext);
        $this->assertStringNotContainsString('private-document.pdf', $storedAttachmentCiphertext);
        $this->assertSame('private-document.pdf', $appointment->fresh()->attachments[0]['name']);
    }

    public function test_encrypted_submission_data_remains_mutable_as_an_array_object(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $submission = SlipSubmission::create([
            'user_id' => $user->id,
            'slip' => 'client-information-form',
            'data' => ['student_name' => 'Ada'],
            'pdf_path' => 'slips/example.pdf',
        ]);

        $submission->data['student_name'] = 'Grace';
        $submission->save();

        $this->assertSame('Grace', $submission->fresh()->data['student_name']);
        $this->assertStringNotContainsString(
            'Grace',
            DB::table('slip_submissions')->where('id', $submission->id)->value('data'),
        );
    }

    public function test_migration_encrypts_sensitive_values_that_were_already_stored(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $renameMigration = require database_path('migrations/2026_11_12_104200_rename_cns_requests_to_slip_informations.php');
        $renameMigration->down();

        $migration = require database_path('migrations/2026_11_11_160000_encrypt_sensitive_record_data.php');

        $migration->down();

        DB::table('cns_requests')->insert([
            'user_id' => $student->id,
            'full_name' => 'Legacy Student',
            'student_id' => 'OLD-123',
            'email' => 'legacy@example.test',
            'degree' => 'BSCS',
            'year_level' => '2',
            'school_year' => '2025-2026',
            'purpose' => 'Transcript',
            'pdf_path' => 'cns/legacy.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('slip_submissions')->insert([
            'user_id' => $student->id,
            'slip' => 'client-information-form',
            'data' => json_encode(['personal_concern' => 'Legacy private detail'], JSON_THROW_ON_ERROR),
            'pdf_path' => 'slips/legacy.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('slip_assignments')->insert([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'notes' => 'Legacy referral note',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('appointments')->insert([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDay(),
            'purpose' => 'Legacy appointment purpose',
            'action_taken' => 'Legacy action taken',
            'attachments' => json_encode([['name' => 'legacy-private.pdf']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $storedRequest = DB::table('cns_requests')->first();
        $storedAttachments = DB::table('appointments')->value('attachments');
        $this->assertSame('Legacy Student', Crypt::decryptString($storedRequest->full_name));
        $this->assertSame('Transcript', Crypt::decryptString($storedRequest->purpose));
        $this->assertSame('Legacy private detail', SlipSubmission::query()->firstOrFail()->data['personal_concern']);
        $this->assertSame('Legacy referral note', SlipAssignment::query()->firstOrFail()->notes);
        $this->assertSame('Legacy action taken', Appointment::query()->firstOrFail()->action_taken);
        $this->assertSame('legacy-private.pdf', Appointment::query()->firstOrFail()->attachments[0]['name']);

        $migration->up();

        $this->assertSame($storedAttachments, DB::table('appointments')->value('attachments'));
        $this->assertSame('Legacy Student', Crypt::decryptString(DB::table('cns_requests')->first()->full_name));
        $this->assertSame('Legacy private detail', SlipSubmission::query()->firstOrFail()->data['personal_concern']);
        $this->assertSame('Legacy action taken', Appointment::query()->firstOrFail()->action_taken);

        $renameMigration->up();
        $this->assertSame('Legacy Student', SlipInformation::query()->firstOrFail()->full_name);
    }

    public function test_identity_migration_encrypts_existing_account_and_roster_data_and_can_be_retried(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $sessionId = Str::random(40);
        $notificationId = (string) Str::uuid();
        DB::table('users')->where('id', $user->id)->update([
            'name' => 'Legacy Student',
            'name_lookup_hash' => null,
            'email' => 'legacy-student@example.test',
            'email_lookup_hash' => null,
            'id_number' => 'LEGACY-123',
            'id_number_lookup_hash' => null,
            'gender' => 'female',
            'remember_token' => 'legacy-remember-token',
        ]);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.15',
            'user_agent' => 'Legacy Browser',
            'payload' => base64_encode('legacy-session-payload'),
            'last_activity' => now()->timestamp,
        ]);
        DB::table('account_session_logs')->insert([
            'user_id' => $user->id,
            'session_hash' => hash('sha256', $sessionId),
            'device_name' => 'Legacy Desktop',
            'latitude' => 37.7749000,
            'longitude' => -122.4194000,
            'logged_in_at' => now(),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(1, DB::table('account_session_logs')->count());
        DB::table('cache')->insert([
            'key' => 'legacy-cache-key',
            'value' => 'legacy-cache-value',
            'expiration' => now()->addHour()->timestamp,
        ]);
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'App\\Notifications\\EmailOtp',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['message' => 'legacy-notification'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'legacy-student@example.test',
            'token' => 'already-hashed-token',
            'created_at' => now(),
        ]);
        DB::table('student_references')->insert([
            'id_number' => 'LEGACY-123',
            'name' => 'Legacy Student',
            'gender' => 'female',
            'year' => '2nd Year',
            'course' => 'BSCS',
            'source_data' => json_encode(['Code' => 'LEGACY-123'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_11_204000_encrypt_account_identity_and_session_data.php');
        $migration->up();

        $storedUser = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame('Legacy Student', Crypt::decryptString($storedUser->name));
        $this->assertSame('legacy-student@example.test', Crypt::decryptString($storedUser->email));
        $this->assertSame('LEGACY-123', Crypt::decryptString($storedUser->id_number));
        $this->assertSame('female', Crypt::decryptString($storedUser->gender));
        $this->assertSame('legacy-remember-token', Crypt::decryptString($storedUser->remember_token));
        $this->assertSame(
            SensitiveLookup::hash('email', 'legacy-student@example.test'),
            $storedUser->email_lookup_hash,
        );
        $this->assertSame(
            SensitiveLookup::hash('id_number', 'LEGACY-123'),
            $storedUser->id_number_lookup_hash,
        );
        $storedSession = DB::table('sessions')
            ->where('id', SensitiveLookup::hash('session_id', $sessionId))
            ->firstOrFail();
        $this->assertSame('legacy-session-payload', Crypt::decryptString(base64_decode($storedSession->payload, true)));
        $this->assertSame('192.0.2.15', Crypt::decryptString($storedSession->ip_address));
        $this->assertSame('Legacy Browser', Crypt::decryptString($storedSession->user_agent));
        $storedSessionLog = DB::table('account_session_logs')->first();
        $this->assertNotNull($storedSessionLog);
        $this->assertSame('Legacy Desktop', Crypt::decryptString($storedSessionLog->device_name));
        $this->assertSame('37.7749', Crypt::decryptString($storedSessionLog->latitude));
        $this->assertSame('-122.4194', Crypt::decryptString($storedSessionLog->longitude));
        $this->assertSame('legacy-cache-value', Crypt::decryptString(DB::table('cache')->where('key', 'legacy-cache-key')->value('value')));
        $this->assertSame(
            'legacy-notification',
            json_decode(Crypt::decryptString(DB::table('notifications')->where('id', $notificationId)->value('data')), true, flags: JSON_THROW_ON_ERROR)['message'],
        );
        $this->assertSame(
            'legacy-notification',
            DatabaseNotification::query()->findOrFail($notificationId)->data['message'],
        );
        $this->assertSame(
            SensitiveLookup::hash('email', 'legacy-student@example.test'),
            DB::table('password_reset_tokens')->value('email'),
        );

        $reference = StudentReference::query()->where('id_number_lookup_hash', SensitiveLookup::hash('id_number', 'LEGACY-123'))->firstOrFail();
        $this->assertSame('Legacy Student', $reference->name);
        $this->assertSame('2nd Year', $reference->year);
        $this->assertSame(['Code' => 'LEGACY-123'], $reference->source_data->getArrayCopy());

        $migration->up();

        $this->assertSame('Legacy Student', User::query()->findOrFail($user->id)->name);
        $this->assertSame('Legacy Student', $reference->fresh()->name);
        $this->assertSame(['Code' => 'LEGACY-123'], $reference->fresh()->source_data->getArrayCopy());
    }

    public function test_database_cache_and_sessions_remain_usable_with_encrypted_storage(): void
    {
        $cache = Cache::store('database');
        $cache->put('encryption-test', 'Private cache value', 60);

        $storedCacheValue = DB::table('cache')
            ->where('key', config('cache.prefix').'encryption-test')
            ->value('value');
        $this->assertNotSame('Private cache value', $storedCacheValue);
        $this->assertSame('Private cache value', $cache->get('encryption-test'));

        $cache->put('encryption-counter', 10, 60);
        $this->assertSame(11, $cache->increment('encryption-counter'));
        $this->assertSame(11, $cache->get('encryption-counter'));

        $sessionId = Str::random(40);
        $sessionData = 'Private session value';
        $handler = new EncryptedDatabaseSessionHandler(DB::connection(), 'sessions', 120, app());

        $this->assertTrue($handler->write($sessionId, $sessionData));
        $storedSession = DB::table('sessions')
            ->where('id', SensitiveLookup::hash('session_id', $sessionId))
            ->firstOrFail();
        $this->assertStringNotContainsString($sessionData, $storedSession->payload);
        $this->assertSame($sessionData, $handler->read($sessionId));
    }
}
