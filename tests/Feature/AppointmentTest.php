<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\SlipAssignment;
use App\Models\User;
use App\Notifications\ReferralAppointmentUpdate;
use App\Notifications\WorkflowActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_appointment_request_notifies_guidance_with_the_student_and_reason(): void
    {
        Notification::fake();
        $student = User::factory()->create(['name' => 'Jamie Student', 'role' => 'student']);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $date = now()->addDays(5);

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => $date->format('Y-m-d'),
                'scheduled_time' => '10:00',
                'purpose' => 'Discuss academic support',
            ])
            ->assertRedirect(route('appointments.index'));

        Notification::assertSentTo(
            $counselor,
            WorkflowActivity::class,
            fn (WorkflowActivity $notification): bool => $notification->toDatabase($counselor)['message']
                === 'Jamie Student requested an appointment for Discuss academic support.',
        );
    }

    public function test_students_can_request_appointments_and_only_see_their_own(): void
    {
        $student = User::factory()->create([
            'id_number' => 'APPT-STUDENT-1',
            'gender' => 'female',
            'role' => 'student',
        ]);
        $teacher = User::factory()->create([
            'id_number' => 'APPT-TEACHER-1',
            'gender' => 'male',
            'role' => 'teacher',
        ]);
        $appointmentDate = now()->addDays(2)->setTime(8, 0);

        $this->actingAs($student)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('Request an appointment');

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate->format('Y-m-d'),
                'scheduled_time' => $appointmentDate->format('H:i'),
                'purpose' => 'Discuss academic support',
            ])
            ->assertRedirect(route('appointments.index'))
            ->assertSessionHas('status');

        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame($student->id, $appointment->user_id);
        $this->assertSame('pending', $appointment->status);
        $this->assertEquals($appointmentDate, $appointment->scheduled_at);

        $this->actingAs($teacher)
            ->post(route('appointments.store'), [
                'scheduled_date' => now()->addDays(3)->format('Y-m-d'),
                'scheduled_time' => now()->addDays(3)->startOfHour()->format('H:i'),
                'purpose' => 'Discuss a student referral',
            ])
            ->assertRedirect(route('teacher.dashboard'));

        $this->actingAs($student)
            ->get(route('appointments.index'))
            ->assertSee('Discuss academic support')
            ->assertDontSee('Discuss a student referral');
    }

    public function test_students_can_only_request_available_appointment_slots_for_the_selected_day(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $appointmentDate = now()->addDays(5)->format('Y-m-d');

        Appointment::create([
            'user_id' => $otherStudent->id,
            'scheduled_at' => $appointmentDate.' 09:00:00',
            'purpose' => 'Existing request',
        ]);
        $this->assertSame(
            1,
            Appointment::query()
                ->where('scheduled_at', $appointmentDate.' 09:00:00')
                ->whereIn('status', ['pending', 'pending_confirmation', 'reschedule_requested', 'accepted'])
                ->count(),
        );

        $this->actingAs($student)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('8:00 AM')
            ->assertSee('11:00 AM')
            ->assertSee('1:00 PM')
            ->assertSee('5:00 PM');

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate,
                'scheduled_time' => '09:00',
                'purpose' => 'Request an already reserved time',
            ])
            ->assertSessionHasErrors('scheduled_time');

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate,
                'scheduled_time' => '10:00',
                'purpose' => 'Request an available time',
            ])
            ->assertRedirect(route('appointments.index'))
            ->assertSessionHas('status');

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate,
                'scheduled_time' => '12:00',
                'purpose' => 'Request an unavailable hour',
            ])
            ->assertSessionHasErrors('scheduled_time');

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_student_requests_cannot_overlap_another_reserved_appointment(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $appointmentDate = now()->addDays(2);

        Appointment::create([
            'user_id' => $otherStudent->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(8, 30),
            'duration_minutes' => 60,
            'purpose' => 'Existing appointment',
            'status' => 'accepted',
        ]);

        $this->actingAs($student)
            ->from(route('appointments.index'))
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate->format('Y-m-d'),
                'scheduled_time' => '09:00',
                'purpose' => 'Overlapping appointment',
            ])
            ->assertRedirect(route('appointments.index'))
            ->assertSessionHasErrors('scheduled_time');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_guidance_cannot_accept_an_appointment_that_overlaps_a_reservation(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create(['role' => 'student']);
        $anotherStudent = User::factory()->create(['role' => 'student']);
        $appointmentDate = now()->addDays(2);
        $request = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(8, 0),
            'purpose' => 'Appointment request',
            'status' => 'pending',
        ]);
        Appointment::create([
            'user_id' => $anotherStudent->id,
            'scheduled_at' => $appointmentDate->copy()->setTime(9, 0),
            'duration_minutes' => 60,
            'purpose' => 'Already reserved appointment',
            'status' => 'accepted',
        ]);

        $this->actingAs($counselor)
            ->from(route('appointments.index'))
            ->post(route('appointments.accept', $request), [
                'scheduled_date' => $appointmentDate->format('Y-m-d'),
                'scheduled_time' => '09:30',
                'duration_minutes' => 30,
            ])
            ->assertRedirect(route('appointments.index'))
            ->assertSessionHasErrors('scheduled_time');

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_guidance_office_cannot_receive_more_than_five_appointments_on_one_date(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $anotherStudent = User::factory()->create(['role' => 'student']);
        $appointmentDate = now()->addDays(2);

        foreach (['08:00', '09:00', '10:00', '11:00', '13:00'] as $time) {
            Appointment::create([
                'user_id' => $anotherStudent->id,
                'scheduled_at' => $appointmentDate->format('Y-m-d').' '.$time,
                'purpose' => 'Reserved appointment',
                'status' => 'pending',
            ]);
        }

        $this->actingAs($student)
            ->from(route('appointments.index'))
            ->post(route('appointments.store'), [
                'scheduled_date' => $appointmentDate->format('Y-m-d'),
                'scheduled_time' => '14:00',
                'purpose' => 'Sixth appointment',
            ])
            ->assertRedirect(route('appointments.index'))
            ->assertSessionHasErrors('scheduled_date');

        $this->assertDatabaseCount('appointments', 5);
    }

    public function test_guidance_can_accept_or_decline_appointment_requests_and_declines_require_a_reason(): void
    {
        $student = User::factory()->create([
            'id_number' => 'APPT-STUDENT-2',
            'gender' => 'female',
            'role' => 'student',
        ]);
        $counselor = User::factory()->create([
            'id_number' => 'APPT-COUNSELOR-1',
            'gender' => 'male',
            'role' => 'guidance_counselor',
        ]);
        $acceptedAppointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'Accepted meeting',
        ]);
        $declinedAppointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDays(4),
            'purpose' => 'Declined meeting',
        ]);

        $this->actingAs($counselor)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee($student->name)
            ->assertSee('Reason for declining');
        $this->actingAs($counselor)
            ->get(route('guidance.dashboard'))
            ->assertOk()
            ->assertSee('Waiting for Appointment Approval');

        $this->actingAs($counselor)
            ->post(route('appointments.accept', $acceptedAppointment), [
                'scheduled_at' => $acceptedAppointment->scheduled_at->toDateTimeString(),
            ])
            ->assertRedirect(route('appointments.index'));
        $this->assertSame('accepted', $acceptedAppointment->fresh()->status);
        $this->assertSame($counselor->id, $acceptedAppointment->fresh()->reviewed_by);

        $this->actingAs($counselor)
            ->post(route('appointments.decline', $declinedAppointment), [])
            ->assertSessionHasErrors('decline_reason');
        $this->assertSame('pending', $declinedAppointment->fresh()->status);

        $this->actingAs($counselor)
            ->post(route('appointments.decline', $declinedAppointment), [
                'decline_reason' => 'That time is unavailable. Please choose another time.',
            ])
            ->assertRedirect(route('appointments.index'));
        $this->assertSame('declined', $declinedAppointment->fresh()->status);
        $this->assertSame($counselor->id, $declinedAppointment->fresh()->reviewed_by);

        $this->actingAs($student)
            ->get(route('appointments.index'))
            ->assertSee('That time is unavailable. Please choose another time.')
            ->assertDontSee('Reason for declining');
    }

    public function test_guidance_appointment_list_shows_name_and_concern_before_details_popup(): void
    {
        $student = User::factory()->create([
            'name' => 'Appointment Summary Student',
            'id_number' => 'APPT-SUMMARY-1',
            'role' => 'student',
        ]);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $appointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'Academic support concern',
        ]);

        $response = $this->actingAs($counselor)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('appointment-details-'.$appointment->id, false)
            ->assertSee('Appointment Details')
            ->assertSee('Student suggestion:')
            ->assertSee('Accept appointment');

        preg_match(
            '/<button\b(?=[^>]*aria-controls="appointment-details-'.$appointment->id.'")[^>]*>(.*?)<\/button>/s',
            $response->getContent(),
            $summaryMatch,
        );

        $this->assertNotEmpty($summaryMatch);
        $summary = html_entity_decode(strip_tags($summaryMatch[1]), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString($student->name, $summary);
        $this->assertStringContainsString('Academic support concern', $summary);
        $this->assertStringNotContainsString('Student ·', $summary);
        $this->assertStringNotContainsString($appointment->scheduled_at->format('M j, Y'), $summary);
    }

    public function test_guidance_groups_all_appointment_requests_under_each_student(): void
    {
        $student = User::factory()->create([
            'name' => 'John No Doe',
            'role' => 'student',
        ]);
        $otherStudent = User::factory()->create([
            'name' => 'Another Student',
            'role' => 'student',
        ]);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $firstAppointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'First concern',
        ]);
        $secondAppointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDays(3),
            'purpose' => 'Second concern',
        ]);
        Appointment::create([
            'user_id' => $otherStudent->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'Another concern',
        ]);

        $response = $this->actingAs($counselor)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('John No Doe')
            ->assertSee('2 appointment requests')
            ->assertSee('First concern')
            ->assertSee('Second concern');

        preg_match_all(
            '/<details data-student-appointment-group="'.$student->id.'">/',
            $response->getContent(),
            $studentGroups,
        );

        $this->assertCount(1, $studentGroups[0]);
        $response->assertSee('appointment-details-'.$firstAppointment->id, false)
            ->assertSee('appointment-details-'.$secondAppointment->id, false);
    }

    public function test_referral_appointment_requires_student_confirmation_and_notifies_the_teacher(): void
    {
        Notification::fake();

        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $referral = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'status' => 'submitted',
        ]);
        $proposedDate = now()->addDays(3)->startOfHour();

        $this->actingAs($counselor)
            ->get(route('guidance.referrals'))
            ->assertOk()
            ->assertSee('Set a counseling appointment');

        $this->actingAs($counselor)
            ->post(route('guidance.referrals.appoint', $referral), [
                'scheduled_date' => $proposedDate->format('Y-m-d'),
                'scheduled_time' => $proposedDate->format('H:i'),
                'duration_minutes' => 60,
                'concerns' => 'Class attendance support',
            ])
            ->assertSessionHas('status');

        $appointment = Appointment::query()->where('referral_id', $referral->id)->firstOrFail();
        $this->assertSame('pending_confirmation', $appointment->status);
        $this->actingAs($counselor)
            ->get(route('guidance.referrals'))
            ->assertOk()
            ->assertSee('Class Admission cannot be sent until the counseling appointment is marked done.');
        Notification::assertSentTo($teacher, ReferralAppointmentUpdate::class, fn (ReferralAppointmentUpdate $notification, array $channels): bool => $notification->toDatabase($teacher)['title'] === 'Counseling appointment proposed'
            && $notification->toDatabase($teacher)['message'] === "Based on the referral cause for {$student->name} (Class attendance support), the counselor assigned a counseling appointment, so the result for your referral might be delayed."
            && $notification->toDatabase($teacher)['url'] === route('teacher.referrals-sent').'#referral-'.$referral->id
            && in_array('mail', $channels, true)
        );
        Notification::assertSentTo(
            $student,
            ReferralAppointmentUpdate::class,
            fn (ReferralAppointmentUpdate $notification): bool => $notification->toDatabase($student)['message']
                === "Guidance Counselor assigned an appointment for you on {$appointment->scheduled_at->format('M j, Y g:i A')} about Class attendance support.",
        );

        $this->actingAs($student)
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('Confirm this appointment')
            ->assertSee('Suggest another available time');

        $suggestedDate = now()->addDays(4)->startOfHour();
        $this->actingAs($student)
            ->post(route('appointments.referral.suggest', $appointment), [
                'scheduled_date' => $suggestedDate->format('Y-m-d'),
                'scheduled_time' => $suggestedDate->format('H:i'),
                'reschedule_reason' => 'I am available after classes.',
            ])
            ->assertSessionHas('status');
        $this->assertSame('reschedule_requested', $appointment->fresh()->status);
        Notification::assertSentTo($teacher, ReferralAppointmentUpdate::class, fn (ReferralAppointmentUpdate $notification): bool => $notification->toDatabase($teacher)['title'] === 'Student suggested a different counseling time'
        );

        $confirmedDate = now()->addDays(5)->startOfHour();
        $this->actingAs($counselor)
            ->patch(route('guidance.referrals.appointments.update', [$referral, $appointment]), [
                'scheduled_date' => $confirmedDate->format('Y-m-d'),
                'scheduled_time' => $confirmedDate->format('H:i'),
                'duration_minutes' => 60,
                'concerns' => 'Class attendance support',
            ])
            ->assertSessionHas('status');
        $this->assertSame('pending_confirmation', $appointment->fresh()->status);

        $this->actingAs($student)
            ->post(route('appointments.referral.confirm', $appointment))
            ->assertSessionHas('status');
        $this->assertSame('accepted', $appointment->fresh()->status);

        $this->actingAs($counselor)
            ->post(route('appointments.complete', $appointment), [
                'action_taken' => 'Counseling session completed.',
                'concerns' => 'Class attendance support',
            ])
            ->assertRedirect(route('appointments.index'));
        $this->assertSame('done', $appointment->fresh()->status);
        Notification::assertSentTo($teacher, ReferralAppointmentUpdate::class, fn (ReferralAppointmentUpdate $notification): bool => $notification->toDatabase($teacher)['title'] === 'Counseling appointment completed'
        );
    }

    public function test_users_can_review_and_mark_notifications_as_read(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        Mail::fake();
        $teacher->notify(new ReferralAppointmentUpdate(
            'proposed',
            'Notification Student',
            now()->addDays(2)->format('M j, Y g:i A'),
            'Class attendance support',
            1,
            1,
        ));

        $this->actingAs($teacher)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Counseling appointment proposed')
            ->assertSee('Notification Student');

        $notification = $teacher->unreadNotifications()->firstOrFail();
        $this->actingAs($teacher)
            ->from(route('notifications.index'))
            ->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('notifications.index'));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'notifiable_id' => $teacher->id,
        ]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_only_students_and_teachers_can_request_and_only_guidance_can_review(): void
    {
        $counselor = User::factory()->create([
            'id_number' => 'APPT-COUNSELOR-2',
            'gender' => 'female',
            'role' => 'guidance_counselor',
        ]);
        $appointment = Appointment::create([
            'user_id' => $counselor->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'Test request',
        ]);
        $student = User::factory()->create([
            'id_number' => 'APPT-STUDENT-3',
            'gender' => 'male',
            'role' => 'student',
        ]);

        $this->actingAs($counselor)
            ->post(route('appointments.store'), [
                'scheduled_date' => now()->addDays(2)->format('Y-m-d'),
                'scheduled_time' => now()->addDays(2)->format('H:i'),
                'purpose' => 'Invalid requester',
            ])
            ->assertForbidden();

        $this->actingAs($student)
            ->post(route('appointments.accept', $appointment))
            ->assertForbidden();
        $this->actingAs($student)
            ->post(route('appointments.decline', $appointment), ['decline_reason' => 'Not allowed'])
            ->assertForbidden();
        $this->assertSame('pending', $appointment->fresh()->status);
    }

    public function test_appointment_requests_must_be_scheduled_for_a_future_time(): void
    {
        $student = User::factory()->create([
            'id_number' => 'APPT-STUDENT-4',
            'gender' => 'female',
            'role' => 'student',
        ]);

        $this->actingAs($student)
            ->post(route('appointments.store'), [
                'scheduled_date' => now()->subDay()->format('Y-m-d'),
                'scheduled_time' => '08:00',
                'purpose' => 'Past meeting',
            ])
            ->assertSessionHasErrors('scheduled_date');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_guidance_can_record_that_a_client_failed_to_attend(): void
    {
        $counselor = User::factory()->create([
            'id_number' => 'APPT-COUNSELOR-MISSED',
            'gender' => 'female',
            'role' => 'guidance_counselor',
        ]);
        $student = User::factory()->create([
            'id_number' => 'APPT-STUDENT-MISSED',
            'gender' => 'male',
            'role' => 'student',
        ]);
        $appointment = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subHour(),
            'purpose' => 'Missed meeting',
            'status' => 'accepted',
        ]);

        $this->actingAs($counselor)
            ->post(route('appointments.missed', $appointment))
            ->assertRedirect(route('appointments.index'));

        $this->assertSame('missed', $appointment->fresh()->status);
        $this->assertSame('Client failed to attend', $appointment->fresh()->action_taken);
    }

    public function test_guidance_receives_one_notification_for_each_overdue_appointment_needing_completion(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create(['role' => 'student', 'name' => 'Overdue Test Student']);
        $overdue = Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subMinute(),
            'purpose' => 'Overdue meeting',
            'status' => 'accepted',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addHour(),
            'purpose' => 'Future meeting',
            'status' => 'accepted',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subHour(),
            'purpose' => 'Completed meeting',
            'status' => 'done',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subHour(),
            'purpose' => 'Missed meeting',
            'status' => 'missed',
        ]);

        $this->artisan('appointments:notify-overdue')->assertSuccessful();

        $this->actingAs($counselor)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Overdue appointment needs follow-up')
            ->assertSee('Overdue Test Student')
            ->assertSee('Mark it as done or missed.');

        $this->artisan('appointments:notify-overdue')->assertSuccessful();

        $this->assertSame(1, $counselor->notifications()->count());
        $notification = $counselor->notifications()->firstOrFail();
        $this->assertSame($overdue->id, $notification->data['appointment_id']);
    }
}
