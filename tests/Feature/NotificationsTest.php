<?php

namespace Tests\Feature;

use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\User;
use App\Notifications\OverdueAppointmentReminder;
use App\Notifications\ReferralAppointmentUpdate;
use App\Notifications\WorkflowActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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

    public function test_dashboard_notifications_are_emailed_to_users_with_registered_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'notifications@example.test',
            'role' => 'guidance_counselor',
        ]);
        $notifications = [
            new WorkflowActivity(
                'New update',
                'The update details are ready.',
                route('student.dashboard'),
                'test',
            ),
            new ReferralAppointmentUpdate(
                'proposed',
                'Student Example',
                now()->addDay()->format('M j, Y g:i A'),
                'Academic support',
                1,
                1,
            ),
            new OverdueAppointmentReminder(
                1,
                $user->id,
                'Student Example',
                now()->subDay()->format('M j, Y g:i A'),
            ),
        ];

        foreach ($notifications as $notification) {
            $user->notify($notification);
            Notification::assertSentTo(
                $user,
                $notification::class,
                fn (object $sentNotification, array $channels): bool => in_array('database', $channels, true)
                    && in_array('mail', $channels, true),
            );
        }

        $overdue = $notifications[2];
        $this->assertInstanceOf(MailMessage::class, $overdue->toMail($user));
    }

    public function test_overdue_appointment_reminder_is_not_emailed_without_a_registered_address(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => null,
            'role' => 'guidance_counselor',
        ]);

        $user->notify(new OverdueAppointmentReminder(
            1,
            $user->id,
            'Student Example',
            now()->subDay()->format('M j, Y g:i A'),
        ));

        Notification::assertSentTo(
            $user,
            OverdueAppointmentReminder::class,
            fn (OverdueAppointmentReminder $notification, array $channels): bool => $channels === ['database'],
        );
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
