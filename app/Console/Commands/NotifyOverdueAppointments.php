<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Notifications\OverdueAppointmentReminder;
use Illuminate\Console\Command;

class NotifyOverdueAppointments extends Command
{
    protected $signature = 'appointments:notify-overdue';

    protected $description = 'Notify guidance counselors about overdue appointments that still need follow-up';

    public function handle(): int
    {
        $counselors = User::query()
            ->where('role', 'guidance_counselor')
            ->where('is_active', true)
            ->get();

        if ($counselors->isEmpty()) {
            return self::SUCCESS;
        }

        Appointment::query()
            ->with('requester')
            ->where('status', 'accepted')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($appointments) use ($counselors): void {
                foreach ($appointments as $appointment) {
                    foreach ($counselors as $counselor) {
                        $notification = new OverdueAppointmentReminder(
                            (int) $appointment->id,
                            (int) $counselor->id,
                            $appointment->requester?->name ?? 'the client',
                            $appointment->scheduled_at->format('M j, Y g:i A'),
                        );

                        if (! $counselor->notifications()->whereKey($notification->id)->exists()) {
                            $counselor->notify($notification);
                        }
                    }
                }
            });

        return self::SUCCESS;
    }
}
