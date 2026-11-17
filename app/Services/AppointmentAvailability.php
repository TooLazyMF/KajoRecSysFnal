<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AppointmentAvailability
{
    public const DAILY_LIMIT = 5;

    /**
     * Appointments in these states reserve a counselor's time and daily capacity.
     *
     * @var array<int, string>
     */
    public const RESERVED_STATUSES = [
        'pending',
        'pending_confirmation',
        'reschedule_requested',
        'accepted',
        'done',
        'missed',
    ];

    public function lockSchedule(): void
    {
        User::query()
            ->where('role', 'guidance_counselor')
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function reservations(): Collection
    {
        return Appointment::query()
            ->whereNotNull('scheduled_at')
            ->whereDate('scheduled_at', '>=', today())
            ->whereIn('status', self::RESERVED_STATUSES)
            ->get(['id', 'scheduled_at', 'duration_minutes']);
    }

    /**
     * @return array<int, array{id: int, date: string, time: string, duration: int}>
     */
    public function calendarReservations(): array
    {
        return $this->reservations()
            ->map(fn (Appointment $appointment): array => [
                'id' => (int) $appointment->id,
                'date' => $appointment->scheduled_at->format('Y-m-d'),
                'time' => $appointment->scheduled_at->format('H:i'),
                'duration' => $appointment->duration_minutes ?? 60,
            ])
            ->values()
            ->all();
    }

    public function assertAvailable(
        CarbonInterface $scheduledAt,
        int $durationMinutes = 60,
        ?int $ignoreAppointmentId = null,
    ): void {
        $dayAppointments = Appointment::query()
            ->whereDate('scheduled_at', $scheduledAt->toDateString())
            ->whereIn('status', self::RESERVED_STATUSES)
            ->when(
                $ignoreAppointmentId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreAppointmentId),
            )
            ->get(['id', 'scheduled_at', 'duration_minutes']);

        $requestedStart = Carbon::instance($scheduledAt);
        $requestedEnd = $requestedStart->copy()->addMinutes($durationMinutes);

        $hasConflict = $dayAppointments->contains(function (Appointment $appointment) use ($requestedStart, $requestedEnd): bool {
            $existingStart = $appointment->scheduled_at;
            $existingEnd = $existingStart->copy()->addMinutes($appointment->duration_minutes ?? 60);

            return $requestedStart->lt($existingEnd) && $requestedEnd->gt($existingStart);
        });

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'scheduled_time' => 'This time overlaps another appointment. Please choose a different time.',
            ]);
        }

        if ($dayAppointments->count() >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'The Guidance Office already has five appointments on this date. Please choose another date.',
            ]);
        }
    }
}
