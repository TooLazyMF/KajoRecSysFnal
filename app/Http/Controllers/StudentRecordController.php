<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\SlipAssignment;
use App\Models\SlipInformation;
use App\Models\SlipSubmission;
use App\Models\User;
use App\Support\SlipCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StudentRecordController extends Controller
{
    public function dashboard(Request $request): View
    {
        $this->authorizeGuidance($request);

        $students = User::query()
            ->where('role', 'student')
            ->with([
                'slipSubmissions' => fn ($query) => $query
                    ->where('slip', 'qf-bo-oss-04-client-information-form')
                    ->latest(),
                'slipAssignments' => fn ($query) => $query
                    ->whereIn('slip', ['student-referral-slip', 'class-admission-slip'])
                    ->where('status', 'approved')
                    ->whereNotNull('completed_at')
                    ->with([
                        'slipSubmission',
                        'appointments' => fn ($appointments) => $appointments
                            ->whereIn('status', ['accepted', 'done', 'missed'])
                            ->orderByDesc('scheduled_at'),
                    ]),
                'appointments' => fn ($query) => $query
                    ->whereIn('status', ['done', 'missed'])
                    ->whereNotNull('completed_at')
                    ->with('reviewer')
                    ->orderByDesc('completed_at'),
            ])
            ->orderBy('id')
            ->get();

        return view('admin.student-records', [
            'students' => $students,
            'cifDefinition' => SlipCatalog::all()['qf-bo-oss-04-client-information-form'],
        ]);
    }

    public function monthlyReport(Request $request): View
    {
        $this->authorizeGuidance($request);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $validated['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $appointments = Appointment::query()
            ->with('requester')
            ->whereHas('requester', fn ($query) => $query->where('role', 'student'))
            ->whereBetween('scheduled_at', [$start, $end])
            ->get();
        $availedAppointments = $appointments->whereIn('status', ['accepted', 'done', 'missed']);

        return view('admin.reports.monthly', [
            'month' => $month,
            'monthLabel' => $start->format('F Y'),
            'studentsAvailed' => $availedAppointments->pluck('user_id')->unique()->count(),
            'totalAppointments' => $appointments->count(),
            'scheduledAppointments' => $availedAppointments->where('status', 'accepted')->count(),
            'doneAppointments' => $availedAppointments->where('status', 'done')->count(),
            'missedAppointments' => $availedAppointments->where('status', 'missed')->count(),
            'studentAppointments' => $availedAppointments
                ->groupBy('user_id')
                ->map(fn ($studentAppointments) => [
                    'student' => $studentAppointments->first()->requester,
                    'appointments' => $studentAppointments,
                ])
                ->sortBy(fn (array $row) => $row['student']->name)
                ->values(),
        ]);
    }

    public function index(Request $request, string $type): View
    {
        $this->authorizeGuidance($request);
        abort_unless(in_array($type, ['all', 'slips', 'appointments'], true), 404);

        $students = User::query()
            ->where('role', 'student')
            ->where('is_active', true)
            ->where('is_graduated', false)
            ->where(function ($query) use ($type) {
                if ($type === 'slips') {
                    $query->whereHas('slipAssignments')
                        ->orWhereHas('slipSubmissions')
                        ->orWhereHas('slipInformations');
                } elseif ($type === 'appointments') {
                    $query->whereHas('appointments');
                }
            })
            ->withCount(['appointments', 'slipAssignments', 'slipSubmissions', 'slipInformations'])
            ->orderBy('id')
            ->get();

        return view('admin.record-history.index', compact('students', 'type'));
    }

    public function show(Request $request, User $student): View
    {
        $this->authorizeGuidance($request);
        abort_unless($student->role === 'student', 404);

        $slips = SlipCatalog::all();
        $records = collect();
        $assignmentBundles = SlipAssignment::query()
            ->where('student_id', $student->id)
            ->pluck('bundle_id', 'slip_submission_id');

        SlipSubmission::query()->where('user_id', $student->id)->latest()->get()->each(function (SlipSubmission $submission) use ($slips, $records, $assignmentBundles) {
            $records->push([
                'type' => 'Slip',
                'title' => $slips[$submission->slip]['title'] ?? 'Slip submission',
                'date' => $submission->created_at,
                'status' => $submission->review_status,
                'details' => null,
                'bundle_id' => $assignmentBundles[$submission->id] ?? null,
                'url' => route('slips.submissions.show', $submission),
            ]);
        });

        SlipAssignment::query()->where('student_id', $student->id)->latest()->get()->each(function (SlipAssignment $assignment) use ($slips, $records) {
            if ($assignment->slip_submission_id) {
                return;
            }
            $records->push([
                'type' => 'Slip',
                'title' => $slips[$assignment->slip]['title'] ?? str($assignment->slip)->replace('-', ' ')->title().' assignment',
                'date' => $assignment->completed_at ?? $assignment->created_at,
                'status' => $assignment->status,
                'details' => null,
                'bundle_id' => $assignment->bundle_id,
                'url' => null,
            ]);
        });

        SlipInformation::query()->where('user_id', $student->id)->latest()->get()->each(function (SlipInformation $slipInformation) use ($records) {
            $records->push([
                'type' => 'Slip',
                'title' => 'Slip Information',
                'date' => $slipInformation->created_at,
                'status' => $slipInformation->review_status,
                'details' => $slipInformation->purpose,
                'url' => route('slip-informations.show', $slipInformation),
            ]);
        });

        Appointment::query()->where('user_id', $student->id)->latest()->get()->each(function (Appointment $appointment) use ($records) {
            $records->push([
                'type' => 'Appointment',
                'title' => 'Guidance appointment',
                'date' => $appointment->scheduled_at,
                'status' => $appointment->status,
                'details' => $appointment->concerns ?? $appointment->purpose,
                'action_taken' => $appointment->action_taken,
                'appointment_id' => $appointment->id,
                'url' => null,
            ]);
        });

        $records = $records->sortByDesc('date')->values();
        $referralTracking = $this->referralTracking($student->id);

        return view('admin.record-history.show', [
            'student' => $student,
            'appointmentRecords' => $records->where('type', 'Appointment')->values(),
            'otherRecords' => $records->where('type', '!=', 'Appointment')->values(),
            'referralTracking' => $referralTracking,
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function referralTracking(int $studentId)
    {
        $referrals = SlipAssignment::query()
            ->with(['student', 'slipSubmission', 'followups.slipSubmission'])
            ->where('student_id', $studentId)
            ->where('slip', 'student-referral-slip')
            ->latest('completed_at')
            ->get();

        return $referrals->map(function (SlipAssignment $referral): array {
            $steps = collect([$referral])->merge($referral->followups->sortBy('created_at'));

            return [
                'referral' => $referral,
                'steps' => $steps,
                'status' => $referral->followups->contains(fn (SlipAssignment $step) => $step->status === 'approved')
                    ? 'Follow-up approved'
                    : ($referral->followups->contains(fn (SlipAssignment $step) => $step->status === 'submitted')
                        ? 'Follow-up submitted'
                        : str_replace('_', ' ', $referral->status)),
            ];
        })->values();
    }

    public function archived(Request $request): View
    {
        $this->authorizeGuidance($request);

        $students = User::query()
            ->where('role', 'student')
            ->where(function ($query) {
                $query->where('is_active', false)->orWhere('is_graduated', true);
            })
            ->withCount(['appointments', 'slipAssignments', 'slipSubmissions', 'slipInformations'])
            ->orderBy('id')
            ->get();

        return view('admin.record-history.archived', compact('students'));
    }

    private function authorizeGuidance(Request $request): void
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
    }
}
