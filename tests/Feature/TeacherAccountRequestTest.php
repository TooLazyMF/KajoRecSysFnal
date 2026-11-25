<?php

namespace Tests\Feature;

use App\Models\RosterImportState;
use App\Models\TeacherAccountRequest;
use App\Models\TeacherReference;
use App\Models\User;
use App\Notifications\TeacherAccountRequestDecision;
use App\Notifications\TeacherAccountRequestVerification;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TeacherAccountRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_account_is_created_only_after_email_verification_and_counselor_approval(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->importTeacher('A. Teacher');

        $this->submitRequest();

        $teacherRequest = TeacherAccountRequest::query()
            ->where('email_lookup_hash', SensitiveLookup::hash('email', 'a.teacher@example.com'))
            ->firstOrFail();
        $photoPath = $teacherRequest->id_photo_path;
        $this->assertDatabaseMissing('users', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'a.teacher@example.com'),
        ]);
        Notification::assertSentOnDemand(TeacherAccountRequestVerification::class);

        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $this->actingAs($counselor)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Awaiting email verification')
            ->assertDontSee(route('admin.teacher-requests.photo', $teacherRequest));

        $this->actingAs($counselor)
            ->post(route('admin.teacher-requests.approve', $teacherRequest))
            ->assertSessionHasErrors('request');
        $this->assertSame(TeacherAccountRequest::PENDING, $teacherRequest->fresh()->status);

        $verificationUrl = URL::temporarySignedRoute(
            'teacher-registration.verify',
            now()->addHour(),
            ['teacherAccountRequest' => $teacherRequest->id],
        );
        $this->get($verificationUrl)
            ->assertRedirect(route('teacher.register'))
            ->assertSessionHas('status');

        $teacherRequest->refresh();
        $this->assertNotNull($teacherRequest->email_verified_at);
        $this->assertDatabaseMissing('users', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'a.teacher@example.com'),
        ]);

        $this->actingAs($counselor)
            ->get(route('admin.teacher-requests.photo', $teacherRequest))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($counselor)
            ->post(route('admin.teacher-requests.approve', $teacherRequest))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $teacher = User::query()
            ->where('email_lookup_hash', SensitiveLookup::hash('email', 'a.teacher@example.com'))
            ->firstOrFail();
        $this->assertSame('teacher', $teacher->role);
        $this->assertSame('A. Teacher', $teacher->name);
        $this->assertSame('10001', $teacher->id_number);
        $this->assertTrue($teacher->is_active);
        $this->assertTrue($teacher->hasVerifiedEmail());
        $this->assertTrue(Hash::check('TeacherPassword123', $teacher->password));
        $this->assertSame(TeacherAccountRequest::APPROVED, $teacherRequest->fresh()->status);
        $this->assertNull($teacherRequest->fresh()->password);
        $this->assertNull($teacherRequest->fresh()->id_photo_path);
        Storage::disk('local')->assertMissing($photoPath);
        Notification::assertSentOnDemand(TeacherAccountRequestDecision::class);

        auth()->guard('web')->logout();
        $this->post(route('login.store'), [
            'email' => 'a.teacher@example.com',
            'password' => 'TeacherPassword123',
        ])->assertRedirect(route('teacher.dashboard'));
        $this->assertAuthenticatedAs($teacher);
    }

    public function test_declined_teacher_request_deletes_photo_notifies_teacher_and_can_be_resubmitted(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->importTeacher('A. Teacher');
        $this->submitRequest();

        $teacherRequest = TeacherAccountRequest::query()
            ->where('email_lookup_hash', SensitiveLookup::hash('email', 'a.teacher@example.com'))
            ->firstOrFail();
        $photoPath = $teacherRequest->id_photo_path;

        $this->get($this->verificationUrl($teacherRequest))
            ->assertRedirect(route('teacher.register'));

        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $this->actingAs($counselor)
            ->post(route('admin.teacher-requests.decline', $teacherRequest), [
                'decision_message' => 'Please upload a clearer ID photo.',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status');

        $teacherRequest->refresh();
        $this->assertSame(TeacherAccountRequest::DECLINED, $teacherRequest->status);
        $this->assertSame('Please upload a clearer ID photo.', $teacherRequest->decision_message);
        $this->assertNull($teacherRequest->password);
        $this->assertNull($teacherRequest->id_photo_path);
        Storage::disk('local')->assertMissing($photoPath);
        Notification::assertSentOnDemand(TeacherAccountRequestDecision::class);

        auth()->guard('web')->logout();
        $this->submitRequest();

        $teacherRequest->refresh();
        $this->assertSame(TeacherAccountRequest::PENDING, $teacherRequest->status);
        $this->assertNull($teacherRequest->email_verified_at);
        $this->assertNotNull($teacherRequest->id_photo_path);
        $this->assertTrue(Hash::check('TeacherPassword123', $teacherRequest->password));
    }

    public function test_teacher_request_rejects_non_numeric_teacher_ids(): void
    {
        Notification::fake();
        Storage::fake('local');
        $this->importTeacher('A. Teacher');

        $this->from(route('teacher.register'))
            ->post(route('teacher.register.store'), [
                'teacher_name' => 'A. Teacher',
                'teacher_id' => 'TEACHER-001',
                'email' => 'invalid-id@example.com',
                'id_photo' => $this->fakeIdPhoto(),
                'password' => 'TeacherPassword123',
                'password_confirmation' => 'TeacherPassword123',
            ])
            ->assertRedirect(route('teacher.register'))
            ->assertSessionHasErrors('teacher_id');

        $this->assertDatabaseMissing('teacher_account_requests', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'invalid-id@example.com'),
        ]);
        Notification::assertNothingSent();
    }

    private function importTeacher(string $name): void
    {
        TeacherReference::create([
            'name' => $name,
            'name_lookup_hash' => SensitiveLookup::hash('name', TeacherReference::normalizeName($name)),
        ]);
        RosterImportState::markImported(RosterImportState::TEACHERS);
    }

    private function submitRequest(): void
    {
        $this->post(route('teacher.register.store'), [
            'teacher_name' => 'A. Teacher',
            'teacher_id' => '10001',
            'email' => 'a.teacher@example.com',
            'id_photo' => $this->fakeIdPhoto(),
            'password' => 'TeacherPassword123',
            'password_confirmation' => 'TeacherPassword123',
        ])->assertRedirect(route('teacher.register'))
            ->assertSessionHasNoErrors();
    }

    private function verificationUrl(TeacherAccountRequest $teacherRequest): string
    {
        return URL::temporarySignedRoute(
            'teacher-registration.verify',
            now()->addHour(),
            ['teacherAccountRequest' => $teacherRequest->id],
        );
    }

    private function fakeIdPhoto(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'teacher-id.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/r3sAAAAASUVORK5CYII=', true),
        );
    }
}
