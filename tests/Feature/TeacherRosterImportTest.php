<?php

namespace Tests\Feature;

use App\Models\RosterImportState;
use App\Models\TeacherAccountRequest;
use App\Models\TeacherReference;
use App\Models\User;
use App\Notifications\TeacherAccountRequestVerification;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherRosterImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_requests_must_use_a_name_from_the_imported_list(): void
    {
        Notification::fake();
        Storage::fake('local');
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);

        $this->actingAs($counselor)->post(route('admin.teachers.import'), [
            'teacher_file' => UploadedFile::fake()->createWithContent(
                'teachers.csv',
                "Teacher Name\nNew T. Teacher\nOther Teacher\n",
            ),
        ])->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('status');

        $this->assertSame(2, TeacherReference::query()->count());
        $this->assertTrue(RosterImportState::isImported(RosterImportState::TEACHERS));

        $this->actingAs($counselor)->post(route('admin.teachers.import'), [
            'teacher_file' => UploadedFile::fake()->createWithContent(
                'updated-teachers.csv',
                "New T. Teacher\n",
            ),
        ])->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('status');

        $this->assertSame(1, TeacherReference::query()->count());
        $teacherNameHash = SensitiveLookup::hash('name', TeacherReference::normalizeName('New T. Teacher'));
        $this->assertSame('New T. Teacher', TeacherReference::query()->where('name_lookup_hash', $teacherNameHash)->value('name'));
        $this->assertNotSame('New T. Teacher', DB::table('teacher_references')->where('name_lookup_hash', $teacherNameHash)->value('name'));

        auth()->guard('web')->logout();
        $this->get(route('teacher.register'))
            ->assertOk()
            ->assertSee('name="teacher_name"', false)
            ->assertSee('New T. Teacher');

        $this->from(route('teacher.register'))->post(route('teacher.register.store'), [
            'teacher_name' => 'Other Teacher',
            'teacher_id' => 'TEACHER-OTHER-001',
            'email' => 'other.teacher@gmail.com',
            'password' => 'initialPassword123',
            'password_confirmation' => 'initialPassword123',
            'id_photo' => $this->fakeIdPhoto(),
        ])->assertRedirect(route('teacher.register'))
            ->assertSessionHasErrors('teacher_name');

        $this->assertDatabaseMissing('teacher_account_requests', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'other.teacher@gmail.com'),
        ]);

        $this->post(route('teacher.register.store'), [
            'teacher_name' => 'New T. Teacher',
            'teacher_id' => 'TEACHER-NEW-001',
            'email' => 'new.teacher@gmail.com',
            'password' => 'initialPassword123',
            'password_confirmation' => 'initialPassword123',
            'id_photo' => $this->fakeIdPhoto(),
        ])->assertRedirect(route('teacher.register'))
            ->assertSessionHasNoErrors();

        $teacherRequest = TeacherAccountRequest::query()
            ->where('email_lookup_hash', SensitiveLookup::hash('email', 'new.teacher@gmail.com'))
            ->firstOrFail();
        $this->assertSame('New T. Teacher', $teacherRequest->name);
        $this->assertSame(TeacherAccountRequest::PENDING, $teacherRequest->status);
        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'new.teacher@gmail.com')]);
        $this->assertNotNull($teacherRequest->id_photo_path);
        Storage::disk('local')->assertExists($teacherRequest->id_photo_path);
        $storedPhoto = Storage::disk('local')->get($teacherRequest->id_photo_path);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", Crypt::decryptString($storedPhoto));
        Notification::assertSentOnDemand(TeacherAccountRequestVerification::class);
    }

    public function test_teacher_requests_are_blocked_until_the_teacher_list_is_reimported(): void
    {
        Notification::fake();
        Storage::fake('local');
        TeacherReference::create([
            'name' => 'Legacy Teacher',
            'name_lookup_hash' => SensitiveLookup::hash(
                'name',
                TeacherReference::normalizeName('Legacy Teacher'),
            ),
        ]);

        $this->from(route('teacher.register'))
            ->post(route('teacher.register.store'), [
                'teacher_name' => 'Legacy Teacher',
                'teacher_id' => 'TEACHER-LEGACY-001',
                'email' => 'legacy.teacher@example.com',
                'password' => 'initialPassword123',
                'password_confirmation' => 'initialPassword123',
                'id_photo' => $this->fakeIdPhoto(),
            ])
            ->assertRedirect(route('teacher.register'))
            ->assertSessionHasErrors([
                'teacher_name' => 'The guidance counselor has not imported the teacher list yet.',
            ]);

        $this->assertDatabaseMissing('teacher_account_requests', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'legacy.teacher@example.com'),
        ]);
        $this->assertFalse(RosterImportState::isImported(RosterImportState::TEACHERS));
    }

    public function test_teacher_name_import_rejects_files_with_more_than_one_column(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);

        $this->actingAs($counselor)
            ->from(route('guidance.dashboard'))
            ->post(route('admin.teachers.import'), [
                'teacher_file' => UploadedFile::fake()->createWithContent(
                    'teachers.csv',
                    "Teacher Name,Email\nJane Doe,jane@example.com\n",
                ),
            ])
            ->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHasErrors('teacher_file');

        $this->assertSame(0, TeacherReference::query()->count());
    }

    private function fakeIdPhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'teacher-id.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/r3sAAAAASUVORK5CYII=', true),
        );
    }
}
