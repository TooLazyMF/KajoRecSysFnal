<?php

namespace Tests\Feature;

use App\Models\TeacherReference;
use App\Models\User;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeacherRosterImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_teacher_names_are_used_as_the_teacher_account_creation_reference(): void
    {
        Notification::fake();
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);

        $this->actingAs($counselor)->post(route('admin.teachers.import'), [
            'teacher_file' => UploadedFile::fake()->createWithContent(
                'teachers.csv',
                "Teacher Name\nNew T. Teacher\nOther Teacher\n",
            ),
        ])->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('status');

        $this->assertSame(2, TeacherReference::query()->count());

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

        $this->actingAs($counselor)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('View imported teacher names (1)')
            ->assertSee('New T. Teacher');

        $this->actingAs($counselor)->post(route('admin.teachers.store'), [
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'middle_initial' => 'T.',
            'teacher_id' => 'TEACHER-OTHER-001',
            'email' => 'other.teacher@gmail.com',
            'password' => 'initialPassword123',
            'password_confirmation' => 'initialPassword123',
            'counselor_password' => 'password',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('first_name');

        $this->assertDatabaseMissing('users', [
            'id_number_lookup_hash' => SensitiveLookup::hash('id_number', 'TEACHER-OTHER-001'),
        ]);

        $this->actingAs($counselor)->post(route('admin.teachers.store'), [
            'first_name' => 'New',
            'last_name' => 'Teacher',
            'middle_initial' => 'T.',
            'teacher_id' => 'TEACHER-NEW-001',
            'email' => 'new.teacher@gmail.com',
            'password' => 'initialPassword123',
            'password_confirmation' => 'initialPassword123',
            'counselor_password' => 'password',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'id_number_lookup_hash' => SensitiveLookup::hash('id_number', 'TEACHER-NEW-001'),
        ]);
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
}
