<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NotifyUpcomingAppointments extends Command
{
    protected $signature = 'appointments:notify-upcoming';

    protected $description = 'Remind students and guidance counselors about appointments starting in one hour';

    public function handle(): int
    {
        $now = now();
        $windowStart = $now->copy()->addMinutes(59);
        $windowEnd = $now->copy()->addMinutes(61);
        $activeCounselors = User::query()
            ->where('role', 'guidance_counselor')
            ->where('is_active', true)
            ->get();

        Appointment::query()
            ->with(['requester', 'reviewer'])
            ->where('status', 'accepted')
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->whereHas('requester', fn ($query) => $query->where('role', 'student'))
            ->orderBy('id')
            ->chunkById(100, function ($appointments) use ($activeCounselors): void {
                foreach ($appointments as $appointment) {
                    $student = $appointment->requester;
                    if (! $student instanceof User) {
                        continue;
                    }

                    $scheduledAt = $appointment->scheduled_at;
                    if ($scheduledAt === null) {
                        continue;
                    }

                    $reviewer = $appointment->reviewer;
                    $hasAssignedCounselor = $reviewer instanceof User
                        && $reviewer->role === 'guidance_counselor'
                        && $reviewer->is_active;
                    $counselors = $hasAssignedCounselor
                        ? collect([$reviewer])
                        : $activeCounselors;

                    $counselorName = $hasAssignedCounselor
                        ? $reviewer->name
                        : 'your guidance counselor';
                    $this->notifyIfMissing(
                        $student,
                        $appointment,
                        $counselorName,
                        $scheduledAt,
                    );

                    foreach ($counselors as $counselor) {
                        $this->notifyIfMissing(
                            $counselor,
                            $appointment,
                            $student->name,
                            $scheduledAt,
                        );
                    }
                }
            });

        return self::SUCCESS;
    }

    private function notifyIfMissing(
        User $recipient,
        Appointment $appointment,
        string $otherParticipantName,
        string $scheduledAt,
    ): void {
        $notification = new AppointmentReminder(
            (int) $appointment->id,
            (int) $recipient->id,
            $otherParticipantName,
            Carbon::parse($scheduledAt),
        );

        if (! $recipient->notifications()->whereKey($notification->id)->exists()) {
            $recipient->notify($notification);
        }
    }
}
