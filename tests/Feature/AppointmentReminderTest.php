<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_hour_reminder_is_sent_to_the_student_and_assigned_counselor_once(): void
    {
        Notification::fake();

        $student = User::factory()->create([
            'name' => 'Appointment Student',
            'email' => 'student@example.test',
            'role' => 'student',
        ]);
        $counselor = User::factory()->create([
            'name' => 'Appointment Counselor',
            'email' => 'counselor@example.test',
            'role' => 'guidance_counselor',
        ]);
        $scheduledAt = now()->startOfMinute()->addHour();
        $this->travelTo($scheduledAt->copy()->subHour());

        $appointment = Appointment::create([
            'user_id' => $student->id,
            'reviewed_by' => $counselor->id,
            'scheduled_at' => $scheduledAt,
            'purpose' => 'Appointment reminder test',
            'status' => 'accepted',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'reviewed_by' => $counselor->id,
            'scheduled_at' => $scheduledAt,
            'purpose' => 'Unconfirmed appointment',
            'status' => 'pending_confirmation',
        ]);

        $this->artisan('appointments:notify-upcoming')->assertSuccessful();
        Notification::assertSentToTimes($student, AppointmentReminder::class, 1);
        Notification::assertSentToTimes($counselor, AppointmentReminder::class, 1);
        Notification::assertSentTo(
            $student,
            AppointmentReminder::class,
            fn (AppointmentReminder $notification, array $channels): bool => in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && $notification->toDatabase($student)['message']
                    === "You have an appointment today with {$counselor->name} at {$scheduledAt->format('g:i A')}.",
        );
        Notification::assertSentTo(
            $counselor,
            AppointmentReminder::class,
            fn (AppointmentReminder $notification, array $channels): bool => in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && $notification->toDatabase($counselor)['message']
                    === "You have an appointment today with {$student->name} at {$scheduledAt->format('g:i A')}.",
        );
    }

    public function test_repeated_scheduler_runs_do_not_duplicate_a_reminder(): void
    {
        config(['mail.default' => 'array']);
        $student = User::factory()->create(['role' => 'student']);
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $scheduledAt = now()->startOfMinute()->addHour();
        $this->travelTo($scheduledAt->copy()->subHour());

        Appointment::create([
            'user_id' => $student->id,
            'reviewed_by' => $counselor->id,
            'scheduled_at' => $scheduledAt,
            'purpose' => 'Appointment reminder test',
            'status' => 'accepted',
        ]);

        $this->artisan('appointments:notify-upcoming')->assertSuccessful();
        $this->artisan('appointments:notify-upcoming')->assertSuccessful();

        $this->assertSame(
            1,
            $student->notifications()->where('type', AppointmentReminder::class)->count(),
        );
        $this->assertSame(
            1,
            $counselor->notifications()->where('type', AppointmentReminder::class)->count(),
        );
        $this->assertSame(2, DB::table('notifications')->count());
    }
}
