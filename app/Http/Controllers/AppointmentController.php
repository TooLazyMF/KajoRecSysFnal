<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\SlipAssignment;
use App\Notifications\ReferralAppointmentUpdate;
use App\Services\AppointmentAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['student', 'guidance_counselor'], true), 403);

        $appointments = Appointment::query()
            ->with(['requester', 'reviewer'])
            ->when(
                $user->role !== 'guidance_counselor',
                fn ($query) => $query->where('user_id', $user->id),
            )
            ->latest()
            ->get();
        $appointmentReservations = app(AppointmentAvailability::class)->calendarReservations();

        return view('appointments.index', compact('appointments', 'appointmentReservations'));
    }

    public function appointFromReferral(Request $request, SlipAssignment $referral): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($referral->slip === 'student-referral-slip' && in_array($referral->status, ['submitted', 'approved'], true), 404);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'in:30,60,90,120'],
            'concerns' => ['required', 'string', 'max:10000'],
        ]);
        $scheduledAt = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduled_date'].' '.$validated['scheduled_time'],
            config('app.timezone'),
        );

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'Choose a future appointment date and time.',
            ]);
        }

        $availability = app(AppointmentAvailability::class);
        $appointment = DB::transaction(function () use ($request, $referral, $validated, $scheduledAt, $availability) {
            $availability->lockSchedule();
            $lockedReferral = SlipAssignment::query()
                ->whereKey($referral->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $lockedReferral->slip === 'student-referral-slip'
                    && in_array($lockedReferral->status, ['submitted', 'approved'], true),
                404
            );
            abort_unless(
                ! SlipAssignment::query()
                    ->where('related_assignment_id', $lockedReferral->id)
                    ->where('slip', 'class-admission-slip')
                    ->exists(),
                409,
                'The Class Admission Slip has already been issued for this referral.'
            );

            $latestAppointment = Appointment::query()
                ->where('referral_id', $lockedReferral->id)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();
            abort_unless(
                ! $latestAppointment || ! in_array($latestAppointment->status, ['pending_confirmation', 'reschedule_requested', 'accepted'], true),
                409,
                'This referral already has an active counseling appointment.'
            );

            $availability->assertAvailable($scheduledAt, (int) $validated['duration_minutes']);

            return Appointment::create([
                'user_id' => $lockedReferral->student_id,
                'referral_id' => $lockedReferral->id,
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $validated['duration_minutes'],
                'purpose' => $validated['concerns'],
                'concerns' => $validated['concerns'],
                'status' => 'pending_confirmation',
                'reschedule_reason' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        $this->notifyReferralParticipants($appointment, 'proposed');

        return back()->with('status', 'The appointment proposal was sent to the student for confirmation.');
    }

    public function updateFromReferral(Request $request, SlipAssignment $referral, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'in:30,60,90,120'],
            'concerns' => ['required', 'string', 'max:10000'],
        ]);
        $scheduledAt = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduled_date'].' '.$validated['scheduled_time'],
            config('app.timezone'),
        );

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'Choose a future appointment date and time.',
            ]);
        }

        $availability = app(AppointmentAvailability::class);
        $appointment = DB::transaction(function () use ($request, $referral, $appointment, $validated, $scheduledAt, $availability) {
            $availability->lockSchedule();
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->where('referral_id', $referral->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($lockedAppointment->status, ['pending_confirmation', 'reschedule_requested'], true), 409);

            $availability->assertAvailable($scheduledAt, (int) $validated['duration_minutes'], (int) $lockedAppointment->id);

            $lockedAppointment->update([
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $validated['duration_minutes'],
                'purpose' => $validated['concerns'],
                'concerns' => $validated['concerns'],
                'status' => 'pending_confirmation',
                'reschedule_reason' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $lockedAppointment->fresh();
        });

        $this->notifyReferralParticipants($appointment, 'proposed');

        return back()->with('status', 'The updated appointment proposal was sent to the student.');
    }

    public function confirmReferralProposal(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);

        $availability = app(AppointmentAvailability::class);
        DB::transaction(function () use ($request, $appointment, $availability): void {
            $availability->lockSchedule();
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->where('user_id', $request->user()->id)
                ->whereNotNull('referral_id')
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedAppointment->status === 'pending_confirmation', 409);

            if ($lockedAppointment->scheduled_at) {
                $availability->assertAvailable(
                    $lockedAppointment->scheduled_at,
                    $lockedAppointment->duration_minutes ?? 60,
                    (int) $lockedAppointment->id,
                );
            }

            $lockedAppointment->update([
                'status' => 'accepted',
                'reviewed_at' => now(),
            ]);
        });

        $this->notifyReferralParticipants($appointment->fresh(), 'confirmed');

        return back()->with('status', 'You confirmed the counseling appointment.');
    }

    public function suggestReferralTime(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);

        $validated = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'reschedule_reason' => ['required', 'string', 'max:2000'],
        ]);
        $scheduledAt = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduled_date'].' '.$validated['scheduled_time'],
            config('app.timezone'),
        );

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'Choose a future date and time for your suggested appointment.',
            ]);
        }

        $availability = app(AppointmentAvailability::class);
        DB::transaction(function () use ($request, $appointment, $validated, $scheduledAt, $availability): void {
            $availability->lockSchedule();
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->where('user_id', $request->user()->id)
                ->whereNotNull('referral_id')
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedAppointment->status === 'pending_confirmation', 409);

            $availability->assertAvailable(
                $scheduledAt,
                $lockedAppointment->duration_minutes ?? 60,
                (int) $lockedAppointment->id,
            );

            $lockedAppointment->update([
                'status' => 'reschedule_requested',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => null,
                'reschedule_reason' => $validated['reschedule_reason'],
            ]);
        });

        $this->notifyReferralParticipants($appointment->fresh(), 'suggested');

        return back()->with('status', 'Your available time was sent to Guidance for review.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['student', 'teacher'], true), 403);

        $validated = $request->validate([
            'purpose' => ['required', 'string', 'max:2000'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i', 'in:08:00,09:00,10:00,11:00,13:00,14:00,15:00,16:00,17:00'],
        ]);

        $scheduledAt = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['scheduled_date'].' '.$validated['scheduled_time'],
            config('app.timezone'),
        );

        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_date' => 'Choose a future date and time for your suggested appointment.',
            ]);
        }

        $availability = app(AppointmentAvailability::class);
        DB::transaction(function () use ($validated, $scheduledAt, $request, $availability): void {
            $availability->lockSchedule();
            $availability->assertAvailable($scheduledAt);

            Appointment::create([
                'purpose' => $validated['purpose'],
                'scheduled_at' => $scheduledAt,
                'user_id' => $request->user()->id,
            ]);
        });

        return redirect()
            ->route('appointments.index')
            ->with('status', 'Your appointment request has been sent to the Guidance Office.');
    }

    public function accept(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $validated = $request->validate([
            'scheduled_date' => ['nullable', 'required_without:scheduled_at', 'date'],
            'scheduled_time' => ['nullable', 'required_without:scheduled_at', 'date_format:H:i'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120'],
            'guidance_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $scheduledAt = ! empty($validated['scheduled_at'])
            ? \Illuminate\Support\Carbon::parse($validated['scheduled_at'])
            : \Illuminate\Support\Carbon::parse($validated['scheduled_date'].' '.$validated['scheduled_time']);

        if ($scheduledAt->isPast()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'scheduled_date' => 'Choose a future appointment date and time.',
            ]);
        }

        $availability = app(AppointmentAvailability::class);
        DB::transaction(function () use ($request, $appointment, $scheduledAt, $validated, $availability) {
            $availability->lockSchedule();
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($lockedAppointment->status, ['pending', 'reschedule_requested'], true), 409);

            $availability->assertAvailable(
                $scheduledAt,
                (int) ($validated['duration_minutes'] ?? 60),
                (int) $lockedAppointment->id,
            );

            $lockedAppointment->update([
                'status' => 'accepted',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => $validated['duration_minutes'] ?? 60,
                'guidance_message' => $validated['guidance_message'] ?? null,
                'reschedule_reason' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return redirect()
            ->route('appointments.index')
            ->with('status', 'The appointment request has been accepted.');
    }

    public function decline(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $validated = $request->validate([
            'decline_reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $appointment, $validated) {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(in_array($lockedAppointment->status, ['pending', 'reschedule_requested'], true), 409);

            $lockedAppointment->update([
                'status' => 'declined',
                'decline_reason' => $validated['decline_reason'],
                'reschedule_reason' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return redirect()
            ->route('appointments.index')
            ->with('status', 'The appointment request has been declined and the reason was shared with the requester.');
    }

    public function complete(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $validated = $request->validate([
            'action_taken' => ['required', 'string', 'max:10000'],
            'concerns' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip'],
        ]);

        $files = collect($request->file('attachments', []))
            ->map(fn ($file) => [
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('appointment-attachments', 'local'),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($appointment, $validated, $files, $request): void {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedAppointment->status === 'accepted', 409);

            $lockedAppointment->update([
                'status' => 'done',
                'action_taken' => $validated['action_taken'],
                'concerns' => $validated['concerns'] ?? $lockedAppointment->purpose,
                'completed_at' => now(),
                'attachments' => $files,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        $this->notifyReferralParticipants($appointment->fresh(), 'completed');

        return redirect()
            ->route('appointments.index')
            ->with('status', 'The appointment was marked as done and the session record was saved.');
    }

    public function markMissed(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        DB::transaction(function () use ($request, $appointment): void {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedAppointment->status === 'accepted', 409);
            abort_unless($lockedAppointment->scheduled_at?->isPast(), 409);

            $lockedAppointment->update([
                'status' => 'missed',
                'action_taken' => 'Client failed to attend',
                'completed_at' => now(),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        $this->notifyReferralParticipants($appointment->fresh(), 'missed');

        return redirect()
            ->route('appointments.index')
            ->with('status', 'The appointment was recorded as missed.');
    }

    public function updateActionTaken(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $validated = $request->validate([
            'action_taken' => ['required', 'string', 'max:10000'],
            'concerns' => ['nullable', 'string', 'max:10000'],
        ]);

        $updated = Appointment::query()
            ->whereKey($appointment->id)
            ->whereIn('status', ['accepted', 'done', 'missed'])
            ->update([
                'status' => 'done',
                'action_taken' => $validated['action_taken'],
                'concerns' => $validated['concerns'] ?? $appointment->purpose,
                'completed_at' => $appointment->completed_at ?? now(),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

        abort_unless($updated === 1, 409);

        $this->notifyReferralParticipants($appointment->fresh(), 'completed');

        return redirect()
            ->route('guidance.records.show', $appointment->user_id)
            ->with('status', 'The appointment was marked as done and the action taken was saved to the CIF.');
    }

    public function downloadAttachment(Request $request, Appointment $appointment, int $attachment)
    {
        abort_unless(
            $request->user()->role === 'guidance_counselor' || $appointment->user_id === $request->user()->id,
            403,
        );

        $file = $appointment->attachments[$attachment] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'] ?? 'application/octet-stream',
        ]);
    }

    private function notifyReferralParticipants(Appointment $appointment, string $event): void
    {
        if ($appointment->referral_id === null) {
            return;
        }

        $referral = SlipAssignment::query()
            ->with(['student', 'assignedBy'])
            ->find($appointment->referral_id);
        if (! $referral) {
            return;
        }

        $notification = new ReferralAppointmentUpdate(
            $event,
            $referral->student?->name ?? 'the referred student',
            $appointment->scheduled_at?->format('M j, Y g:i A') ?? 'the proposed time',
            (int) $referral->id,
            (int) $appointment->id,
        );

        if ($referral->assignedBy?->role === 'teacher') {
            if (blank($referral->assignedBy->email)) {
                Log::warning('Referral appointment email notification could not be sent because the referring teacher has no email address.', [
                    'teacher_id' => $referral->assignedBy->id,
                    'referral_id' => $referral->id,
                ]);
            }

            $referral->assignedBy->notify($notification);
        }

        if (! in_array($event, ['confirmed', 'suggested'], true)) {
            $referral->student?->notify($notification);
        }
    }

    public function reappoint(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);

        $validated = $request->validate([
            'reschedule_reason' => ['required', 'string', 'max:2000'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
        ]);

        $scheduledAt = null;
        if (filled($validated['scheduled_date'] ?? null) && filled($validated['scheduled_time'] ?? null)) {
            $scheduledAt = \Illuminate\Support\Carbon::createFromFormat(
                'Y-m-d H:i',
                $validated['scheduled_date'].' '.$validated['scheduled_time'],
                config('app.timezone'),
            );

            if ($scheduledAt->isPast()) {
                throw ValidationException::withMessages([
                    'scheduled_date' => 'Choose a future date and time for your suggested appointment.',
                ]);
            }
        }

        $availability = app(AppointmentAvailability::class);
        DB::transaction(function () use ($request, $appointment, $validated, $scheduledAt, $availability): void {
            $availability->lockSchedule();
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(in_array($lockedAppointment->status, ['accepted', 'declined'], true), 409);

            if ($scheduledAt) {
                $availability->assertAvailable($scheduledAt, 60, (int) $lockedAppointment->id);
            }

            $lockedAppointment->update([
                'status' => 'reschedule_requested',
                'scheduled_at' => $scheduledAt,
                'duration_minutes' => null,
                'reschedule_reason' => $validated['reschedule_reason'],
                'decline_reason' => null,
                'guidance_message' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
        });

        return redirect()
            ->route('appointments.index')
            ->with('status', 'Your re-appointment request has been sent to the Guidance Office.');
    }
}
