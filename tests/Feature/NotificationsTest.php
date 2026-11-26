<?php

namespace Tests\Feature;

use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\User;
use App\Notifications\WorkflowActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.vapid.public_key' => null,
            'webpush.vapid.private_key' => null,
        ]);
    }

    public function test_opening_a_notification_marks_it_read_and_removes_it_from_the_inbox(): void
    {
        $user = User::factory()->create(['email' => null]);
        $user->notify(new WorkflowActivity(
            'New update',
            'The update details are ready.',
            route('student.dashboard'),
            'test',
        ));
        $notification = $user->notifications()->firstOrFail();

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('New update');

        $this->actingAs($user)
            ->get(route('notifications.open', $notification->id))
            ->assertRedirect(parse_url(route('student.dashboard'), PHP_URL_PATH));

        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('New update')
            ->assertSee('You have no notifications.');
    }

    public function test_first_counselor_view_of_a_student_form_notifies_the_student_once_and_guidance_can_send_updates(): void
    {
        Mail::fake();
        $student = User::factory()->create(['email' => null, 'role' => 'student']);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $submission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'student-leaveof-absence-slip',
            'data' => ['leave_reason' => 'Family responsibilities'],
            'pdf_path' => 'slip-submissions/leave-request.pdf',
            'review_status' => 'submitted',
        ]);
        $assignment = SlipAssignment::create([
            'assigned_by' => $student->id,
            'student_id' => $student->id,
            'slip' => 'student-leaveof-absence-slip',
            'status' => 'submitted',
            'slip_submission_id' => $submission->id,
        ]);

        $this->actingAs($counselor)
            ->get(route('slips.submissions.show', $submission))
            ->assertOk();

        $this->assertNotNull($assignment->fresh()->counselor_viewed_at);
        $this->assertDatabaseCount('notifications', 1);
        $viewNotification = $student->notifications()->firstOrFail();
        $this->assertSame(
            'The Student Leave of Absence Slip was seen by the counselor. Please stand by for an update.',
            $viewNotification->data['message'],
        );

        $this->actingAs($counselor)
            ->get(route('slips.submissions.show', $submission))
            ->assertOk();
        $this->assertDatabaseCount('notifications', 1);

        $this->actingAs($counselor)
            ->post(route('guidance.slip-submissions.notify-student', $submission), [
                'message' => 'Please provide the supporting document.',
            ])
            ->assertRedirect(route('slips.submissions.show', $submission))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('notifications', 2);
        $updateNotification = $student->notifications()
            ->get()
            ->first(fn ($notification) => $notification->data['title'] === 'Update from Guidance Counselor');
        $this->assertNotNull($updateNotification);
        $this->assertSame(
            'Guidance Counselor update on your Student Leave of Absence Slip: Please provide the supporting document.',
            $updateNotification->data['message'],
        );
    }
}
