<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\SlipInformation;
use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\TeacherReference;
use App\Models\User;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DataBackupTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'users',
        'slip_informations',
        'slip_submissions',
        'slip_assignments',
        'appointments',
        'student_references',
        'account_session_logs',
        'passkeys',
        'teacher_references',
    ];

    public function test_guidance_can_download_a_kajorec_backup_and_only_guidance_can_use_backup_routes(): void
    {
        $counselor = $this->createUser('guidance_counselor');
        $teacher = $this->createUser('teacher');
        TeacherReference::create([
            'name' => 'Jane Teacher',
            'name_lookup_hash' => SensitiveLookup::hash('name', TeacherReference::normalizeName('Jane Teacher')),
        ]);

        $this->actingAs($counselor)
            ->get(route('guidance.dashboard'))
            ->assertOk()
            ->assertSee('data-backup-modal')
            ->assertSee('Data Backup Or Restore');

        $download = $this->actingAs($counselor)->get(route('admin.data-backup.download'));

        $download->assertOk();
        $this->assertStringContainsString('application/zip', $download->headers->get('Content-Type'));

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($download->baseResponse->getFile()->getPathname()));
        $payload = json_decode($archive->getFromName('database.json'), true, 512, JSON_THROW_ON_ERROR);
        $archive->close();

        $this->assertSame('kajorec-database-backup', $payload['format']);
        $this->assertSame(3, $payload['version']);
        $this->assertSame(self::TABLES, array_keys($payload['tables']));
        $this->assertCount(1, $payload['tables']['teacher_references']);
        $this->assertNotSame('Jane Teacher', $payload['tables']['teacher_references'][0]['name']);
        $this->actingAs($teacher)
            ->get(route('admin.data-backup.download'))
            ->assertRedirect(route('teacher.dashboard'));
        $this->actingAs($teacher)->post(route('admin.data-backup.import'), [
            'backup_file' => UploadedFile::fake()->createWithContent('backup.zip', 'not a backup'),
        ])->assertRedirect(route('teacher.dashboard'));
    }

    public function test_backup_includes_referenced_appointment_attachments(): void
    {
        Storage::fake('local');
        $counselor = $this->createUser('guidance_counselor');
        $student = $this->createUser('student');
        $path = 'appointment-files/session-note.pdf';
        $contents = 'appointment attachment contents';
        Storage::disk('local')->put($path, $contents);

        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => '2026-12-01 09:00:00',
            'purpose' => 'Attachment export test',
            'attachments' => [['name' => 'session-note.pdf', 'path' => $path]],
        ]);

        $download = $this->actingAs($counselor)->get(route('admin.data-backup.download'));
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($download->baseResponse->getFile()->getPathname()));
        $payload = json_decode($archive->getFromName('database.json'), true, 512, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $contents);

        $this->assertSame($hash, $payload['files'][$path]);
        $this->assertSame($contents, $archive->getFromName("files/{$hash}"));
        $archive->close();
    }

    public function test_encrypted_records_can_be_exported_and_imported_without_losing_data(): void
    {
        Storage::fake('local');
        $counselor = $this->createUser('guidance_counselor');
        $student = $this->createUser('student');
        $teacher = $this->createUser('teacher');
        $files = [
            'cns/private-request.pdf' => 'private cns file',
            'slips/private-submission.pdf' => 'private slip file',
            'appointments/private-attachment.pdf' => 'private appointment file',
            'appointments/private-slip-attachment.pdf' => 'private submission attachment',
        ];

        foreach ($files as $path => $contents) {
            Storage::disk('local')->put($path, $contents);
        }

        $request = SlipInformation::create([
            'user_id' => $student->id,
            'full_name' => 'Private Student Name',
            'student_id' => 'PRIVATE-123',
            'email' => 'private@example.test',
            'degree' => 'BSCS',
            'year_level' => '3',
            'school_year' => '2026-2027',
            'purpose' => 'Private counseling purpose',
            'pdf_path' => 'cns/private-request.pdf',
        ]);
        $submission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'client-information-form',
            'data' => [
                'personal_concern' => 'Private form detail',
                '_attachments' => [[
                    'name' => 'private-slip-attachment.pdf',
                    'path' => 'appointments/private-slip-attachment.pdf',
                ]],
            ],
            'pdf_path' => 'slips/private-submission.pdf',
        ]);
        $assignment = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'notes' => 'Private teacher note',
        ]);
        $appointment = Appointment::create([
            'user_id' => $student->id,
            'referral_id' => $assignment->id,
            'scheduled_at' => '2026-12-01 09:00:00',
            'purpose' => 'Private appointment purpose',
            'action_taken' => 'Private action taken',
            'attachments' => [[
                'name' => 'private-attachment.pdf',
                'path' => 'appointments/private-attachment.pdf',
            ]],
        ]);

        $download = $this->actingAs($counselor)->get(route('admin.data-backup.download'));
        $download->assertOk();
        $archiveContents = file_get_contents($download->baseResponse->getFile()->getPathname());
        $this->assertIsString($archiveContents);

        $backup = UploadedFile::fake()->createWithContent('encrypted-backup.zip', $archiveContents);
        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('backup_status');

        $this->assertSame('Private Student Name', $request->fresh()->full_name);
        $this->assertSame('Private form detail', $submission->fresh()->data['personal_concern']);
        $this->assertSame('appointments/private-slip-attachment.pdf', $submission->fresh()->data['_attachments'][0]['path']);
        $this->assertSame('Private teacher note', $assignment->fresh()->notes);
        $this->assertSame('Private appointment purpose', $appointment->fresh()->purpose);
        $this->assertSame('Private action taken', $appointment->fresh()->action_taken);
        $this->assertSame('appointments/private-attachment.pdf', $appointment->fresh()->attachments[0]['path']);
        $this->assertSame(1, SlipInformation::query()->count(), 'Slip information should not be duplicated.');
        $this->assertSame(1, SlipSubmission::query()->count(), 'Slip submissions should not be duplicated.');
        $this->assertSame(1, SlipAssignment::query()->count(), 'Slip assignments should not be duplicated.');
        $this->assertSame(1, Appointment::query()->count(), 'Appointments should not be duplicated.');
    }

    public function test_import_merges_records_for_matching_accounts_and_is_idempotent(): void
    {
        Storage::fake('local');
        $counselor = $this->createUser('guidance_counselor');
        $student = $this->createUser('student', [
            'name' => 'Current Student Name',
            'email' => 'merge.student@example.test',
            'id_number' => 'MERGE-001',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => '2026-12-01 09:00:00',
            'purpose' => 'Existing appointment',
        ]);

        $sourceUser = $student->fresh()->getAttributes();
        $sourceUser['id'] = 1;
        $sourceUser['name'] = 'Name from backup';

        $sourceAppointment = array_fill_keys(Schema::getColumnListing('appointments'), null);
        $sourceAppointment['id'] = 1;
        $sourceAppointment['user_id'] = 1;
        $sourceAppointment['scheduled_at'] = '2026-12-02 10:00:00';
        $sourceAppointment['purpose'] = 'Appointment from other database';
        $sourceAppointment['status'] = 'pending';
        $sourceAppointment['created_at'] = '2026-11-01 12:00:00';
        $sourceAppointment['updated_at'] = '2026-11-01 12:00:00';

        $attachmentPath = 'appointment-files/medical-note.pdf';
        $attachmentContents = 'restored appointment attachment';
        $attachmentHash = hash('sha256', $attachmentContents);
        $sourceAppointment['attachments'] = json_encode([
            ['name' => 'medical-note.pdf', 'path' => $attachmentPath],
        ], JSON_THROW_ON_ERROR);

        $tables = array_fill_keys(self::TABLES, []);
        $tables['users'] = [$sourceUser];
        $tables['appointments'] = [$sourceAppointment];
        $backup = $this->createBackupUpload(
            $tables,
            ['version' => 1, 'files' => [$attachmentPath => $attachmentHash]],
            [$attachmentHash => $attachmentContents],
        );

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($counselor)
                ->from(route('guidance.dashboard'))
                ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
                ->assertRedirect(route('guidance.dashboard'))
                ->assertSessionHas('backup_status');
        }

        $this->assertSame('Current Student Name', $student->fresh()->name);
        $this->assertSame(2, Appointment::query()->count());
        $this->assertDatabaseHas('appointments', [
            'user_id' => $student->id,
            'purpose' => 'Appointment from other database',
        ]);
        $importedAttachments = json_decode(
            DB::table('appointments')->where('purpose', 'Appointment from other database')->value('attachments'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame("kajorec-imports/{$attachmentHash}.pdf", $importedAttachments[0]['path']);
        Storage::disk('local')->assertExists($importedAttachments[0]['path']);
        $this->assertSame($attachmentContents, Storage::disk('local')->get($importedAttachments[0]['path']));
        $this->assertSame(1, DB::table('backup_import_mappings')
            ->where('table_name', 'appointments')
            ->count());
    }

    public function test_backup_restores_teacher_name_references(): void
    {
        $counselor = $this->createUser('guidance_counselor');
        $teacherName = 'Jane T. Teacher';
        $lookupHash = SensitiveLookup::hash('name', TeacherReference::normalizeName($teacherName));
        TeacherReference::create([
            'name' => $teacherName,
            'name_lookup_hash' => $lookupHash,
        ]);

        $download = $this->actingAs($counselor)->get(route('admin.data-backup.download'));
        $archiveContents = file_get_contents($download->baseResponse->getFile()->getPathname());
        $this->assertIsString($archiveContents);
        $backup = UploadedFile::fake()->createWithContent('teacher-roster.zip', $archiveContents);
        TeacherReference::query()->delete();

        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('backup_status');

        $this->assertSame($teacherName, TeacherReference::query()->where('name_lookup_hash', $lookupHash)->value('name'));
    }

    public function test_non_kajorec_archive_is_rejected_without_changing_records(): void
    {
        $counselor = $this->createUser('guidance_counselor');
        $tables = array_fill_keys(self::TABLES, []);
        $backup = $this->createBackupUpload($tables, ['format' => 'not-kajorec']);

        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHasErrors('backup_file');

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, DB::table('backup_import_mappings')->count());
    }

    public function test_previous_version_two_backups_without_teacher_references_remain_importable(): void
    {
        $counselor = $this->createUser('guidance_counselor');
        $backup = $this->createBackupUpload([], ['version' => 2]);

        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('backup_status');
    }

    public function test_import_rejects_accounts_whose_email_and_id_number_match_different_users(): void
    {
        $counselor = $this->createUser('guidance_counselor');
        $emailOwner = $this->createUser('student', [
            'email' => 'email.owner@example.test',
            'id_number' => 'EMAIL-OWNER',
        ]);
        $idOwner = $this->createUser('student', [
            'email' => 'id.owner@example.test',
            'id_number' => 'ID-OWNER',
        ]);
        $sourceUser = $emailOwner->fresh()->getAttributes();
        $sourceUser['id'] = 900;
        $sourceUser['id_number'] = Crypt::encryptString($idOwner->id_number);
        $sourceUser['id_number_lookup_hash'] = SensitiveLookup::hash('id_number', $idOwner->id_number);

        $tables = array_fill_keys(self::TABLES, []);
        $tables['users'] = [$sourceUser];
        $backup = $this->createBackupUpload($tables);

        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.data-backup.import'), ['backup_file' => $backup])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHasErrors('backup_file');

        $this->assertSame(3, User::query()->count());
        $this->assertSame(0, DB::table('backup_import_mappings')->count());
    }

    private function createUser(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
            ...$attributes,
        ]);
    }

    private function createBackupUpload(array $tables, array $overrides = [], array $files = []): UploadedFile
    {
        $schema = [];

        foreach (self::TABLES as $table) {
            $schema[$table] = Schema::getColumnListing($table);
            $tables[$table] ??= [];
        }

        $version = $overrides['version'] ?? 3;

        if ($version === 1) {
            $legacyTables = [];
            $legacySchema = [];

            foreach (['users', 'cns_requests', 'slip_submissions', 'slip_assignments', 'appointments', 'student_references', 'account_session_logs', 'passkeys'] as $table) {
                $currentTable = $table === 'cns_requests' ? 'slip_informations' : $table;
                $legacyTables[$table] = $tables[$currentTable];
                $legacySchema[$table] = $schema[$currentTable];

                if ($table === 'slip_assignments') {
                    $legacySchema[$table] = array_map(
                        static fn (string $column): string => $column === 'slip_information_id' ? 'cns_request_id' : $column,
                        $legacySchema[$table],
                    );
                    foreach ($legacyTables[$table] as &$assignment) {
                        if (is_array($assignment) && array_key_exists('slip_information_id', $assignment)) {
                            $assignment['cns_request_id'] = $assignment['slip_information_id'];
                            unset($assignment['slip_information_id']);
                        }
                    }
                    unset($assignment);
                }
            }

            $tables = $legacyTables;
            $schema = $legacySchema;
        } elseif ($version === 2) {
            unset($tables['teacher_references'], $schema['teacher_references']);
        }

        $payload = [
            'format' => 'kajorec-database-backup',
            'version' => $version,
            'backup_id' => (string) Str::uuid(),
            'generated_at' => now()->toIso8601String(),
            'schema' => $schema,
            'tables' => $tables,
            'files' => [],
            'missing_files' => [],
            ...$overrides,
        ];

        $archivePath = tempnam(sys_get_temp_dir(), 'kajorec-test-');
        $zip = new ZipArchive;
        $this->assertSame(true, $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database.json', json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($files as $hash => $contents) {
            $zip->addFromString("files/{$hash}", $contents);
        }
        $zip->close();

        return new UploadedFile($archivePath, 'backup.zip', 'application/zip', null, true);
    }
}
