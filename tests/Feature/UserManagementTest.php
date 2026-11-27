<?php

namespace Tests\Feature;

use App\Models\StudentReference;
use App\Models\User;
use App\Notifications\EmailOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guidance_can_manage_teacher_and_student_accounts_with_role_appropriate_actions(): void
    {
        $counselor = $this->createUser([
            'role' => 'guidance_counselor',
        ]);
        $teacher = $this->createUser([
            'name' => 'Teacher Account',
            'role' => 'teacher',
        ]);
        $student = $this->createUser([
            'name' => 'Student Account',
            'role' => 'student',
        ]);
        StudentReference::create([
            'id_number' => $student->id_number,
            'name' => $student->name,
            'gender' => $student->gender,
            'year' => '1st Year',
            'course' => 'BSCS',
        ]);
        $this->assertTrue($counselor->is_active);

        $response = $this->actingAs($counselor)->get(route('admin.users.index'));
        $this->assertSame(200, $response->getStatusCode(), $response->headers->get('Location') ?? $response->getContent());
        $response->assertSee('data-system-theme', false)
            ->assertSee('Manage Users')
            ->assertSee('Teacher Account')
            ->assertSee('Student Account')
            ->assertSee('Teacher Account Requests')
            ->assertSee('Deactivate')
            ->assertSee('Mark as graduate');

        $this->actingAs($counselor)
            ->post(route('admin.users.status', $teacher), ['action' => 'deactivate'])
            ->assertRedirect(route('admin.users.index'));
        $this->assertFalse($teacher->fresh()->is_active);

        $this->actingAs($counselor)
            ->post(route('admin.users.status', $student), ['action' => 'graduate'])
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue($student->fresh()->is_graduated);

        $this->actingAs($counselor)
            ->post(route('admin.users.status', $teacher), ['action' => 'activate'])
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue($teacher->fresh()->is_active);
    }

    public function test_teacher_accounts_cannot_be_marked_as_graduates(): void
    {
        $counselor = $this->createUser(['role' => 'guidance_counselor']);
        $teacher = $this->createUser(['role' => 'teacher']);

        $this->actingAs($counselor)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.status', $teacher), ['action' => 'graduate'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('action');

        $this->assertFalse($teacher->fresh()->is_graduated);
    }

    public function test_counselor_can_edit_teacher_before_first_login_but_not_afterward(): void
    {
        $counselor = $this->createUser(['role' => 'guidance_counselor']);
        $teacher = $this->createUser([
            'name' => 'Teacher Account',
            'email' => 'teacher@example.com',
            'role' => 'teacher',
            'password' => 'TeacherPassword123',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->actingAs($counselor)
            ->get(route('admin.users.index'))
            ->assertSee('Edit');
        $this->actingAs($counselor)
            ->get(route('admin.teachers.edit', $teacher))
            ->assertOk();

        $this->actingAs($counselor)
            ->put(route('admin.teachers.update', $teacher), [
                'first_name' => 'Updated',
                'last_name' => 'Teacher',
                'middle_initial' => 'U.',
                'teacher_id' => 'TEACHER-001',
                'email' => $teacher->email,
                'counselor_password' => 'password',
            ])
            ->assertSessionHasErrors('teacher_id');

        $this->actingAs($counselor)
            ->put(route('admin.teachers.update', $teacher), [
                'first_name' => 'Updated',
                'last_name' => 'Teacher',
                'middle_initial' => 'U.',
                'teacher_id' => $teacher->id_number,
                'email' => $teacher->email,
                'counselor_password' => 'password',
            ])
            ->assertRedirect(route('admin.users.index'));

        $teacher->refresh();
        $this->assertSame('Updated U. Teacher', $teacher->name);

        auth()->guard('web')->logout();
        $this->post(route('login.store'), [
            'email' => $teacher->name,
            'password' => 'TeacherPassword123',
        ])->assertRedirect(route('teacher.dashboard'));
        $this->assertDatabaseHas('account_session_logs', ['user_id' => $teacher->id]);

        $this->actingAs($counselor)
            ->get(route('admin.users.index'))
            ->assertSee('Locked after first login')
            ->assertDontSee(route('admin.teachers.edit', $teacher));
        $this->actingAs($counselor)
            ->get(route('admin.teachers.edit', $teacher))
            ->assertForbidden();
        $this->actingAs($counselor)
            ->put(route('admin.teachers.update', $teacher), [
                'first_name' => 'Changed',
                'last_name' => 'Again',
                'middle_initial' => 'C.',
                'teacher_id' => $teacher->id_number,
                'email' => $teacher->email,
                'counselor_password' => 'password',
            ])
            ->assertForbidden();

        $this->assertSame('Updated U. Teacher', $teacher->fresh()->name);
    }

    public function test_teacher_must_complete_profile_and_verify_email_before_dashboard_access(): void
    {
        Notification::fake();

        $teacher = User::forceCreate([
            'name' => 'Teacher To Setup',
            'email' => null,
            'id_number' => null,
            'gender' => null,
            'role' => 'teacher',
            'password' => 'initial-password',
            'must_change_password' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $teacher->name,
            'password' => 'initial-password',
        ])->assertRedirect(route('teacher.profile.edit'));

        $this->assertAuthenticatedAs($teacher);

        $this->get(route('teacher.dashboard'))
            ->assertRedirect(route('teacher.profile.edit'));

        $this->get(route('teacher.profile.edit'))
            ->assertOk()
            ->assertSee('Complete your teacher account')
            ->assertSee('Teacher ID number')
            ->assertSee('id="teacher-profile-setup-modal"', false)
            ->assertSee('showModal()', false);

        $this->put(route('teacher.profile.update'), [
            'email' => 'new.teacher@example.com',
            'id_number' => 'TEACHER-001',
            'password' => 'initial-password',
            'password_confirmation' => 'initial-password',
        ])->assertSessionHasErrors(['id_number', 'password']);

        $this->assertNull($teacher->fresh()->email);
        $this->assertTrue($teacher->fresh()->must_change_password);

        $this->put(route('teacher.profile.update'), [
            'email' => 'new.teacher@example.com',
            'id_number' => '10001',
            'password' => 'UpdatedTeacher123',
            'password_confirmation' => 'UpdatedTeacher123',
        ])->assertRedirect(route('verification.notice'));

        Notification::assertSentTo($teacher, EmailOtp::class);

        $teacher->refresh();
        $this->assertSame('new.teacher@example.com', $teacher->email);
        $this->assertSame('10001', $teacher->id_number);
        $this->assertFalse($teacher->hasVerifiedEmail());
        $this->assertFalse($teacher->must_change_password);
        $this->assertTrue(Hash::check('UpdatedTeacher123', $teacher->password));
        $this->assertFalse(Hash::check('initial-password', $teacher->password));

        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('id="teacher-email-verification-modal"', false)
            ->assertSee('bg-emerald-700', false)
            ->assertSee('dark:text-zinc-100', false)
            ->assertSee('showModal()', false)
            ->assertSee('Enter the 6-digit code sent to your email address');

        $this->get(route('teacher.dashboard'))
            ->assertRedirect(route('verification.notice'));

        Cache::put(
            'email-otp:email-verification:'.$teacher->id,
            ['code_hash' => Hash::make('123456')],
            now()->addMinutes(30),
        );

        $this->post(route('verification.otp.verify'), ['otp' => '123456'])
            ->assertRedirect(route('teacher.dashboard', absolute: false).'?verified=1');

        $this->assertTrue($teacher->fresh()->hasVerifiedEmail());
    }

    public function test_teacher_can_log_in_using_name_after_counselor_created_account_is_verified(): void
    {
        $teacher = User::forceCreate([
            'name' => 'Verified Teacher',
            'email' => 'verified.teacher@example.com',
            'id_number' => '10002',
            'gender' => null,
            'role' => 'teacher',
            'password' => 'TeacherPassword123',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->post(route('login.store'), [
            'email' => $teacher->name,
            'password' => 'TeacherPassword123',
        ])->assertRedirect(route('teacher.dashboard'));

        $this->assertAuthenticatedAs($teacher);
    }

    public function test_students_cannot_access_user_management(): void
    {
        $student = $this->createUser(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('student.dashboard'));
    }

    public function test_deactivated_users_cannot_log_in_or_continue_an_existing_session(): void
    {
        $user = $this->createUser([
            'email' => 'inactive@example.com',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * The role and account-state fields are intentionally guarded on User.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes): User
    {
        $user = User::factory()->create();
        $user->forceFill($attributes)->save();

        return $user;
    }
}
