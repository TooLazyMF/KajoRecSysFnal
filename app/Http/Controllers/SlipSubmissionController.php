<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\RosterImportState;
use App\Models\SlipAssignment;
use App\Models\SlipInformation;
use App\Models\SlipSubmission;
use App\Models\StudentReference;
use App\Models\User;
use App\Notifications\WorkflowActivity;
use App\Services\AppointmentAvailability;
use App\Services\CifDocxHtmlPreview;
use App\Services\CifDocxSampleGenerator;
use App\Services\SlipPdfGenerator;
use App\Services\SlipSubmissionDocxGenerator;
use App\Support\SensitiveLookup;
use App\Support\SlipCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SlipSubmissionController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->role === 'student') {
            return redirect()->route('student.dashboard');
        }

        if ($request->user()->role === 'guidance_counselor') {
            return redirect()->route('guidance.dashboard');
        }

        if ($request->user()->role === 'teacher') {
            return redirect()->route('teacher.dashboard');
        }

        $slips = SlipCatalog::forRole($request->user()->role);
        $preview = $request->query('preview');
        $previewModal = is_string($preview) && isset($slips[$preview])
            ? $preview.'-modal'
            : null;

        return view('slips.index', [
            'slips' => $slips,
            'previewModal' => $previewModal,
        ]);
    }

    public function assignedIndex(Request $request): View
    {
        abort_unless($request->user()->role === 'student', 403);

        $assignments = SlipAssignment::query()
            ->with(['assignedBy', 'slipSubmission', 'slipInformation', 'relatedAssignment.slipSubmission'])
            ->where('student_id', $request->user()->id)
            ->whereIn('slip', SlipCatalog::workflowSlugs())
            ->latest()
            ->get();

        return view('slips.assigned', [
            'assignments' => $assignments,
            'slips' => SlipCatalog::all(),
        ]);
    }

    public function teacherDashboard(Request $request): View
    {
        abort_unless($request->user()->role === 'teacher', 403);

        $referrals = SlipAssignment::query()
            ->where('assigned_by', $request->user()->id)
            ->where('slip', 'student-referral-slip')
            ->latest()
            ->limit(5)
            ->get();

        $completedAdmissions = SlipAssignment::query()
            ->where('slip', 'class-admission-slip')
            ->where('status', 'approved')
            ->whereHas('relatedAssignment', fn ($query) => $query
                ->where('assigned_by', $request->user()->id)
                ->where('slip', 'student-referral-slip'))
            ->latest('approved_at')
            ->limit(10)
            ->get();

        return view('teacher.teacher', compact('referrals', 'completedAdmissions'));
    }

    public function teacherReferralsSent(Request $request): View
    {
        abort_unless($request->user()->role === 'teacher', 403);

        $referrals = SlipAssignment::query()
            ->with(['student', 'assignedBy', 'slipSubmission', 'followups.student', 'followups.assignedBy', 'followups.approver', 'followups.slipSubmission', 'appointments'])
            ->where('assigned_by', $request->user()->id)
            ->where('slip', 'student-referral-slip')
            ->where('status', '!=', 'cancelled')
            ->whereNull('teacher_acknowledged_at')
            ->latest()
            ->get();

        return view('teacher.referrals-sent', compact('referrals'));
    }

    public function teacherStudents(Request $request): View
    {
        abort_unless($request->user()->role === 'teacher', 403);

        $students = User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->get(['id', 'name', 'id_number', 'email', 'gender']);

        $studentCifs = SlipSubmission::query()
            ->whereIn('user_id', $students->pluck('id'))
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->get()
            ->unique('user_id')
            ->mapWithKeys(fn (SlipSubmission $submission) => [$submission->user_id => $submission->data?->getArrayCopy() ?? []]);

        $studentReferences = StudentReference::query()
            ->get(['id_number', 'course', 'year'])
            ->keyBy(fn (StudentReference $reference) => StudentReference::normalizeIdNumber($reference->id_number));

        return view('teacher.students', compact('students', 'studentReferences', 'studentCifs'));
    }

    public function acknowledgeTeacherReferral(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        abort_unless($request->user()->role === 'teacher', 403);
        abort_unless($slipAssignment->assigned_by === $request->user()->id && $slipAssignment->slip === 'student-referral-slip', 404);

        $admission = $slipAssignment->followups()->where('slip', 'class-admission-slip')->where('status', 'approved')->first();
        abort_unless($admission?->student_sent_to_teacher_at !== null, 409, 'The student has not sent the Class Admission result to you yet.');

        $slipAssignment->update(['teacher_acknowledged_at' => now()]);

        return redirect()->route('teacher.referral-history')->with('status', 'Referral acknowledged and moved to history.');
    }

    public function sendClassAdmissionResultToTeacher(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        abort_unless(
            $request->user()->role === 'student'
                && (int) $slipAssignment->student_id === (int) $request->user()->id,
            403
        );
        abort_unless($slipAssignment->slip === 'class-admission-slip' && $slipAssignment->status === 'approved', 409);

        $referral = $slipAssignment->relatedAssignment;
        abort_unless(
            $referral !== null
                && $referral->slip === 'student-referral-slip'
                && (int) $referral->student_id === (int) $request->user()->id
                && $referral->assigned_by !== null
                && (bool) ($referral->slipSubmission?->data['submitted_by_teacher'] ?? false),
            409,
            'This Class Admission result is not linked to a teacher referral.'
        );

        if ($slipAssignment->student_sent_to_teacher_at === null) {
            $slipAssignment->update(['student_sent_to_teacher_at' => now()]);
        }

        $this->notifyWorkflowParticipants(
            [$referral->assignedBy, $slipAssignment->student],
            'Class Admission result forwarded',
            'The student sent the approved Class Admission result to the referring teacher.',
            route('teacher.referral-history'),
            'slip',
            $slipAssignment->id,
        );

        return redirect()->route('assigned-slips.index')->with('status', 'The Class Admission result was sent to your teacher.');
    }

    public function teacherReferralHistory(Request $request): View
    {
        abort_unless($request->user()->role === 'teacher', 403);

        $referrals = SlipAssignment::query()
            ->with(['student', 'assignedBy', 'slipSubmission', 'followups.student', 'followups.assignedBy', 'followups.approver', 'followups.slipSubmission', 'appointments'])
            ->where('assigned_by', $request->user()->id)
            ->where('slip', 'student-referral-slip')
            ->whereNotNull('teacher_acknowledged_at')
            ->whereHas('followups', fn ($query) => $query->where('slip', 'class-admission-slip'))
            ->latest()
            ->get();

        return view('teacher.referral-history', compact('referrals'));
    }

    public function guidanceDashboard(Request $request): View
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $referrals = SlipAssignment::query()
            ->with([
                'student',
                'assignedBy',
                'slipSubmission',
                'appointments' => fn ($query) => $query->latest('created_at'),
                'followups.assignedBy',
                'followups.approver',
            ])
            ->where('slip', 'student-referral-slip')
            ->whereIn('status', ['submitted', 'approved'])
            ->latest('completed_at')
            ->get()
            ->map(function (SlipAssignment $referral) {
                $hasClassAdmission = SlipAssignment::query()
                    ->where('related_assignment_id', $referral->id)
                    ->where('slip', 'class-admission-slip')
                    ->exists();
                $referralData = $referral->slipSubmission?->data?->getArrayCopy() ?? [];
                $appointment = $referral->appointments->first();
                $appointmentRequiresCompletion = $appointment !== null && $appointment->status !== 'done';

                return [
                    'assignment' => $referral,
                    'appointment' => $appointment,
                    'can_issue_client_information' => false,
                    'can_issue_class_admission' => ! $hasClassAdmission && ! $appointmentRequiresCompletion,
                    'appointment_requires_completion' => $appointmentRequiresCompletion,
                    'referral_pdf_ready' => $this->hasReferralDocumentControlData($referralData),
                    'missing_document_control_identifiers' => ! $this->hasReferralDocumentControlIdentifiers($referralData),
                    'document_control_data' => $referralData,
                ];
            })
            ->filter(fn (array $referral) => $referral['can_issue_class_admission']
                || ! $referral['referral_pdf_ready']
                || $referral['appointment_requires_completion']
            )
            ->values();
        $referralsMissingDocumentControlCount = $referrals
            ->where('missing_document_control_identifiers', true)
            ->count();

        return view('admin.admin_dashboard', [
            'referrals' => $referrals,
            'totalStudentsCount' => User::query()->where('role', 'student')->where('is_active', true)->count(),
            'archivedStudentsCount' => User::query()->where('role', 'student')->where(function ($query) {
                $query->where('is_active', false)->orWhere('is_graduated', true);
            })->count(),
            'hasImportedStudentFile' => RosterImportState::isImported(RosterImportState::STUDENTS),
            'hasImportedTeacherFile' => RosterImportState::isImported(RosterImportState::TEACHERS),
            'todayAppointmentsCount' => Appointment::query()->whereDate('scheduled_at', today())->count(),
            'todayAppointments' => Appointment::query()
                ->with('requester')
                ->whereDate('scheduled_at', today())
                ->orderBy('scheduled_at')
                ->get(),
            'todayMissedDoneAppointments' => Appointment::query()
                ->with('requester')
                ->whereDate('scheduled_at', today())
                ->whereIn('status', ['done', 'missed'])
                ->orderBy('scheduled_at')
                ->get(),
            'pendingAppointments' => Appointment::query()
                ->with('requester')
                ->where('status', 'pending')
                ->latest()
                ->get(),
            'calendarAppointments' => Appointment::query()
                ->with('requester')
                ->whereNotNull('scheduled_at')
                ->whereIn('status', ['pending_confirmation', 'reschedule_requested', 'accepted', 'done', 'missed'])
                ->orderBy('scheduled_at')
                ->get(),
            'activeReferralsCount' => $referralsMissingDocumentControlCount,
            'activeReferrals' => SlipAssignment::query()
                ->with('student')
                ->where('slip', 'student-referral-slip')
                ->where('status', 'pending')
                ->latest('completed_at')
                ->get(),
            'upcomingAppointments' => Appointment::query()
                ->with('requester')
                ->where('scheduled_at', '>=', now())
                ->whereIn('status', ['pending', 'pending_confirmation', 'reschedule_requested', 'accepted'])
                ->orderBy('scheduled_at')
                ->limit(6)
                ->get(),
            'missedDoneAppointments' => Appointment::query()
                ->with('requester')
                ->whereIn('status', ['done', 'missed'])
                ->latest('completed_at')
                ->limit(10)
                ->get(),
            'missedDoneAppointmentsCount' => Appointment::query()
                ->whereIn('status', ['done', 'missed'])
                ->count(),
            'pendingAppointmentsCount' => Appointment::query()->where('status', 'pending')->count(),
            'guidanceInboxCount' => SlipAssignment::query()
                ->where(function ($query): void {
                    $query->where(function ($query): void {
                        $query->where('status', 'submitted')
                            ->whereIn('slip', array_values(array_diff(
                                SlipCatalog::workflowSlugs(),
                                ['student-referral-slip']
                            )));
                    })->orWhere(function ($query): void {
                        $query->where('status', 'pending')
                            ->whereIn('slip', array_keys(SlipCatalog::studentRequestForms()))
                            ->whereColumn('assigned_by', 'student_id');
                    });
                })
                ->count(),
        ]);
    }

    public function guidanceReferrals(Request $request): View
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $referrals = SlipAssignment::query()
            ->with([
                'student',
                'slipSubmission',
                'appointments' => fn ($query) => $query->latest('created_at'),
            ])
            ->where('slip', 'student-referral-slip')
            ->whereIn('status', ['submitted', 'approved'])
            ->latest('completed_at')
            ->get()
            ->map(function (SlipAssignment $referral) {
                $hasClassAdmission = SlipAssignment::query()
                    ->where('related_assignment_id', $referral->id)
                    ->where('slip', 'class-admission-slip')
                    ->exists();
                $referralData = $referral->slipSubmission?->data?->getArrayCopy() ?? [];
                $appointment = $referral->appointments->first();
                $appointmentRequiresCompletion = $appointment !== null && $appointment->status !== 'done';

                return [
                    'assignment' => $referral,
                    'can_issue_class_admission' => ! $hasClassAdmission && ! $appointmentRequiresCompletion,
                    'appointment_requires_completion' => $appointmentRequiresCompletion,
                    'referral_pdf_ready' => $this->hasReferralDocumentControlData($referralData),
                    'document_control_data' => $referralData,
                    'client_information_submission' => SlipSubmission::query()
                        ->where('user_id', $referral->student_id)
                        ->where('slip', 'qf-bo-oss-04-client-information-form')
                        ->latest()
                        ->first(),
                    'appointments' => Appointment::query()
                        ->with('reviewer')
                        ->where(function ($query) use ($referral) {
                            $query->where('user_id', $referral->student_id)
                                ->orWhere('referral_id', $referral->id);
                        })
                        ->where('status', 'done')
                        ->orderBy('scheduled_at')
                        ->get(),
                    'appointment' => $appointment,
                ];
            })
            ->filter(fn (array $referral) => $referral['can_issue_class_admission']
                || ! $referral['referral_pdf_ready']
                || $referral['appointment_requires_completion']
            )
            ->values();

        return view('admin.guidance_referrals', [
            'referrals' => $referrals,
            'cifDefinition' => SlipCatalog::all()['qf-bo-oss-04-client-information-form'],
            'cifSampleData' => $this->clientInformationSampleData(),
            'appointmentReservations' => app(AppointmentAvailability::class)->calendarReservations(),
        ]);
    }

    public function guidanceCifIndex(Request $request): View
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $submissions = SlipSubmission::query()
            ->with('user')
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->get();

        return view('admin.guidance_cif_index', compact('submissions'));
    }

    public function guidanceCifShow(
        Request $request,
        SlipSubmission $slipSubmission
    ): View {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($slipSubmission->slip === 'qf-bo-oss-04-client-information-form', 404);

        $appointments = Appointment::query()
            ->with('reviewer')
            ->where(function ($query) use ($slipSubmission) {
                $query->where('user_id', $slipSubmission->user_id)
                    ->orWhereHas('referral', fn ($referralQuery) => $referralQuery->where('student_id', $slipSubmission->user_id));
            })
            ->where('status', 'done')
            ->orderBy('scheduled_at')
            ->get();

        return view('admin.guidance_cif_show', [
            'submission' => $slipSubmission,
            'appointments' => $appointments,
        ]);
    }

    public function guidanceCifDocx(Request $request, SlipSubmission $slipSubmission, CifDocxSampleGenerator $docxGenerator): Response
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($slipSubmission->slip === 'qf-bo-oss-04-client-information-form', 404);

        $appointments = Appointment::query()
            ->with('reviewer')
            ->where(function ($query) use ($slipSubmission) {
                $query->where('user_id', $slipSubmission->user_id)
                    ->orWhereHas('referral', fn ($referralQuery) => $referralQuery->where('student_id', $slipSubmission->user_id));
            })
            ->where('status', 'done')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'date' => ($appointment->scheduled_at ?? $appointment->completed_at)?->format('m/d/Y'),
                'concern' => $appointment->concerns ?? $appointment->purpose,
                'action' => $appointment->action_taken,
                'counselor' => $appointment->reviewer?->name ?? $request->user()->name,
            ])
            ->all();

        return response($docxGenerator->generate(
            $slipSubmission->data?->getArrayCopy() ?? [],
            $appointments,
            $request->user()->name,
        ))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'inline; filename="cif-'.$slipSubmission->id.'-filled.docx"');
    }

    public function sampleClientInformationPdf(Request $request, SlipPdfGenerator $pdfGenerator): Response
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $submission = new SlipSubmission([
            'slip' => 'qf-bo-oss-04-client-information-form',
            'data' => $this->clientInformationSampleData(),
        ]);

        return response($pdfGenerator->generate(
            $submission,
            SlipCatalog::all()['qf-bo-oss-04-client-information-form']
        ))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="sample-cif-alignment.pdf"');
    }

    public function sampleClientInformationDocx(Request $request, CifDocxSampleGenerator $docxGenerator): Response
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        return response($docxGenerator->generate($this->clientInformationSampleData()))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="sample-cif-filled.docx"');
    }

    public function clientInformationDocx(Request $request, SlipAssignment $referral, CifDocxSampleGenerator $docxGenerator): Response
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($referral->slip === 'student-referral-slip', 404);

        $submission = SlipSubmission::query()
            ->where('user_id', $referral->student_id)
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->firstOrFail();
        $appointments = Appointment::query()
            ->with('reviewer')
            ->where('user_id', $referral->student_id)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'date' => ($appointment->scheduled_at ?? $appointment->completed_at)?->format('m/d/Y'),
                'concern' => $appointment->concerns ?? $appointment->purpose,
                'action' => $appointment->action_taken,
                'counselor' => $appointment->reviewer?->name ?? $request->user()->name,
            ])
            ->all();

        return response($docxGenerator->generate(
            $submission->data?->getArrayCopy() ?? [],
            $appointments,
            $request->user()->name,
        ))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="cif-'.$referral->student_id.'-filled.docx"');
    }

    public function clientInformationDocxPreview(
        Request $request,
        SlipAssignment $referral,
        CifDocxSampleGenerator $docxGenerator,
        CifDocxHtmlPreview $htmlPreview
    ): View {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($referral->slip === 'student-referral-slip', 404);

        $submission = SlipSubmission::query()
            ->where('user_id', $referral->student_id)
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->firstOrFail();
        $appointments = Appointment::query()
            ->with('reviewer')
            ->where('user_id', $referral->student_id)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'date' => ($appointment->scheduled_at ?? $appointment->completed_at)?->format('m/d/Y'),
                'concern' => $appointment->concerns ?? $appointment->purpose,
                'action' => $appointment->action_taken,
                'counselor' => $appointment->reviewer?->name ?? $request->user()->name,
            ])
            ->all();

        return view('admin.sample_cif_docx_preview', [
            'documentHtml' => $htmlPreview->render($docxGenerator->generate(
                $submission->data?->getArrayCopy() ?? [],
                $appointments,
                $request->user()->name,
            )),
            'downloadUrl' => route('guidance.referrals.cif-docx', $referral),
        ]);
    }

    public function sampleClientInformationDocxPreview(Request $request, CifDocxSampleGenerator $docxGenerator, CifDocxHtmlPreview $htmlPreview): View
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        return view('admin.sample_cif_docx_preview', [
            'documentHtml' => $htmlPreview->render($docxGenerator->generate($this->clientInformationSampleData())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientInformationSampleData(): array
    {
        return [
            'last_name' => 'Ixia',
            'first_name' => 'Ixia',
            'middle_name' => 'Ix.',
            'student_id' => '1503',
            'contact_number' => '0912 345 6789',
            'course_year' => 'BTLED-IA 3',
            'birth_date' => 'October 8, 2003',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'nationality' => 'Filipino',
            'birth_place' => 'Baguio City, Benguet',
            'email' => 'ixia.ixia@example.com',
            'languages' => 'Filipino, English, Ilocano',
            'address' => 'Barangay Loakan, Baguio City, Benguet',
            'disability' => 'None',
            'indigenous_group' => 'None',
            'pwd' => 'No',
            'student_parent' => 'No',
            'financial_sources' => 'Parents and personal savings',
            'parent_marital_status' => 'Married',
            'father_name' => 'Juan Ixia',
            'mother_name' => 'Maria Ixia',
            'guardian_name' => 'Maria Ixia',
            'father_age' => '48',
            'mother_age' => '46',
            'guardian_relationship' => 'Mother',
            'father_occupation' => 'Civil Engineer',
            'mother_occupation' => 'Public School Teacher',
            'guardian_occupation' => 'Public School Teacher',
            'father_contact' => '0917 222 3344',
            'mother_contact' => '0918 555 6677',
            'guardian_contact' => '0918 555 6677',
            'siblings' => 'Ivy Ixia, 20, BS Information Technology, Single, Student',
            'educational_background' => "Senior High School | Benguet National High School | 2022 | With Honors\nJunior High School | Benguet National High School | 2018 | Conduct Award\nElementary | Loakan Elementary School | 2014 | Academic Excellence",
            'about_me' => "Responsible, kind, and determined\nHardworking and supportive\nCaring and patient\nIvy, because she listens to me\nSafe and grateful\nI enjoyed reading and drawing\nHelpful and inspiring\nI enjoy writing stories\nI feel hopeful and excited\nTo finish my degree and help my family",
            'consent' => ['agree'],
        ];
    }

    public function reviewIndex(Request $request): View
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        $assignments = SlipAssignment::query()
            ->with(['student', 'assignedBy', 'slipSubmission', 'slipInformation'])
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('status', 'submitted')
                        ->whereIn('slip', array_values(array_diff(
                            SlipCatalog::workflowSlugs(),
                            ['student-referral-slip']
                        )));
                })->orWhere(function ($query): void {
                    $query->where('status', 'pending')
                        ->whereIn('slip', array_keys(SlipCatalog::studentRequestForms()))
                        ->whereColumn('assigned_by', 'student_id');
                });
            })
            ->latest('completed_at')
            ->get();

        return view('admin.slip-review', [
            'assignments' => $assignments,
            'slips' => SlipCatalog::all(),
        ]);
    }

    public function approve(
        Request $request,
        SlipAssignment $slipAssignment,
        SlipPdfGenerator $pdfGenerator
    ): RedirectResponse {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless(
            $slipAssignment->slip !== 'student-referral-slip',
            409,
            'Student referrals are received directly and do not require approval.'
        );

        $guidanceData = [];
        if ($slipAssignment->slip === 'class-admission-slip') {
            $guidanceData = $this->validatedGuidanceData($request, SlipCatalog::all()[$slipAssignment->slip]);
        }

        DB::transaction(function () use ($request, $slipAssignment, $guidanceData, $pdfGenerator) {
            $assignment = SlipAssignment::query()
                ->whereKey($slipAssignment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($assignment->status === 'submitted', 409);

            if ($assignment->slip === 'slip_information') {
                $slipInformation = $assignment->slipInformation;
                abort_unless($slipInformation !== null, 409);
                $slipInformation->update([
                    'review_status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);
            } else {
                $submission = $assignment->slipSubmission;
                abort_unless($submission !== null, 409);

                if ($assignment->slip === 'class-admission-slip') {
                    $referral = $assignment->relatedAssignment;
                    $counselingInProgress = $referral?->appointments()
                        ->whereNotIn('status', ['done', 'missed'])
                        ->exists();

                    abort_unless(
                        ! $counselingInProgress,
                        409,
                        'The Class Admission result cannot be released while the student has an ongoing counseling session.'
                    );

                    $definition = SlipCatalog::all()[$assignment->slip];
                    $submission->update([
                        'data' => [
                            ...$submission->data->getArrayCopy(),
                            ...$this->classAdmissionReferralData($assignment),
                            ...$guidanceData,
                        ],
                    ]);

                    if (! Storage::disk('local')->put(
                        $submission->pdf_path,
                        $pdfGenerator->generate($submission->fresh(), $definition)
                    )) {
                        throw new \RuntimeException('The completed Class Admission Slip PDF could not be stored.');
                    }
                }

                $submission->update([
                    'review_status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                ]);
            }

            $assignment->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'student_sent_to_teacher_at' => $assignment->slip === 'class-admission-slip' ? now() : null,
            ]);
        });

        $slipAssignment->refresh()->load(['student', 'assignedBy', 'slipSubmission', 'relatedAssignment.assignedBy']);
        $submissionUrl = $slipAssignment->slipSubmission
            ? route('slips.submissions.show', $slipAssignment->slipSubmission)
            : route('assigned-slips.index');

        if ($slipAssignment->slip === 'class-admission-slip') {
            $this->notifyWorkflowParticipants(
                [$slipAssignment->student],
                'Your Class Admission result is ready',
                'Guidance has released the result for your referral.',
                $submissionUrl,
                'referral-result',
                $slipAssignment->id,
            );

            $teacher = $slipAssignment->relatedAssignment?->assignedBy;
            if ($teacher?->role === 'teacher') {
                $this->notifyWorkflowParticipants(
                    [$teacher],
                    'Referral result released',
                    'The result of '.$slipAssignment->student->name."'s referral is done.",
                    $submissionUrl,
                    'referral-result',
                    $slipAssignment->id,
                );
            }
        } else {
            $participants = [$slipAssignment->student];
            if ($slipAssignment->assignedBy?->role === 'teacher') {
                $participants[] = $slipAssignment->assignedBy;
            }

            $this->notifyWorkflowParticipants(
                $participants,
                'Form approved',
                'The '.$this->slipDisplayName($slipAssignment->slip).' has been approved by Guidance.',
                $submissionUrl,
                'slip',
                $slipAssignment->id,
            );
        }

        return redirect()
            ->route('guidance.slip-review')
            ->with('status', 'The submitted form has been approved.');
    }

    public function history(Request $request): View
    {
        abort_unless($request->user()->role === 'student', 403);

        $slips = SlipCatalog::all();
        $folderLabels = [
            'class_attendance' => 'Class Attendance',
            'psychological_testing' => 'Psychological Testing',
            'student_housing' => 'Student Housing (Dormitory)',
            'academic_deficiencies' => 'Academic Deficiency/ies',
            'scholarship_grant_assistance' => 'Scholarship/Grant Assistance',
            'student_employment' => 'Student Employment (SA/SPES)',
            'other' => 'Other Referrals',
        ];
        $folders = collect($folderLabels)
            ->map(fn (string $title) => ['title' => $title, 'entries' => collect()])
            ->all();

        $referralAssignments = SlipAssignment::query()
            ->with(['slipSubmission', 'followups.slipSubmission'])
            ->where('student_id', $request->user()->id)
            ->where('slip', 'student-referral-slip')
            ->latest()
            ->get();

        foreach ($referralAssignments as $referral) {
            $referralData = $referral->slipSubmission?->data?->getArrayCopy() ?? [];
            $reason = is_array($referralData['referral_reasons'] ?? null)
                ? ($referralData['referral_reasons'][0] ?? 'other')
                : 'other';
            $folderKey = array_key_exists($reason, $folderLabels) ? $reason : 'other';
            $referralSubmission = $referral->slipSubmission;
            $classAdmission = $referral->followups
                ->where('slip', 'class-admission-slip')
                ->sortByDesc('created_at')
                ->first();
            $admissionSubmission = $classAdmission?->slipSubmission;
            $admissionData = $admissionSubmission?->data?->getArrayCopy() ?? [];

            $folders[$folderKey]['entries']->push([
                'kind' => 'referral',
                'title' => 'Teacher Referral',
                'reason' => $folderLabels[$folderKey],
                'submitted_at' => $referralSubmission?->created_at ?? $referral->completed_at ?? $referral->created_at,
                'status' => $referralSubmission?->review_status ?? $referral->status,
                'show_url' => $referralSubmission ? route('slips.submissions.show', $referralSubmission) : null,
                'pdf_url' => $referralSubmission ? route('slips.submissions.pdf', $referralSubmission) : null,
                'admission' => $classAdmission ? [
                    'title' => 'Class Admission Result',
                    'submitted_at' => $admissionSubmission?->created_at ?? $classAdmission->completed_at ?? $classAdmission->created_at,
                    'status' => $admissionData['excused_status'] ?? $admissionSubmission?->review_status ?? $classAdmission->status,
                    'show_url' => $admissionSubmission ? route('slips.submissions.show', $admissionSubmission) : null,
                    'pdf_url' => $admissionSubmission ? route('slips.submissions.pdf', $admissionSubmission) : null,
                ] : null,
            ]);
        }

        $otherHistory = SlipSubmission::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('slip', [
                'qf-bo-oss-04-client-information-form',
                'student-referral-slip',
                'class-admission-slip',
            ])
            ->latest()
            ->get()
            ->map(function (SlipSubmission $submission) use ($slips) {
                return [
                    'title' => $slips[$submission->slip]['title'] ?? 'Assigned Slip',
                    'submitted_at' => $submission->created_at,
                    'status' => $submission->review_status,
                    'show_url' => route('slips.submissions.show', $submission),
                    'pdf_url' => route('slips.submissions.pdf', $submission),
                ];
            });

        $folders['other']['entries'] = $otherHistory->concat(
            SlipInformation::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (SlipInformation $slipInformation) => [
                    'title' => 'Slip Information',
                    'submitted_at' => $slipInformation->created_at,
                    'status' => $slipInformation->review_status,
                    'show_url' => route('slip-informations.show', $slipInformation),
                    'pdf_url' => route('slip-informations.pdf', $slipInformation),
                ])
        )->sortByDesc('submitted_at')->values();

        $folders['appointments'] = [
            'title' => 'Appointments',
            'entries' => Appointment::query()
                ->where('user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'kind' => 'appointment',
                    'title' => $appointment->referral_id ? 'Counseling Appointment' : 'Appointment Request',
                    'submitted_at' => $appointment->created_at,
                    'scheduled_at' => $appointment->scheduled_at,
                    'status' => $appointment->status,
                    'purpose' => $appointment->purpose,
                    'show_url' => route('appointments.index'),
                    'pdf_url' => null,
                ])
                ->values(),
        ];

        $hasHistory = collect($folders)->contains(fn (array $folder) => $folder['entries']->isNotEmpty());

        return view('slips.history', compact('folders', 'hasHistory'));
    }

    public function assign(
        Request $request,
        string $slip,
        SlipPdfGenerator $pdfGenerator
    ): RedirectResponse {
        abort_unless($request->user()->role === 'teacher', 403);
        abort_unless(isset(SlipCatalog::forRole('teacher')[$slip]), 404);

        if ($slip === 'student-referral-slip') {
            $validated = $request->validate([
                'student_id' => [
                    'required',
                    Rule::exists('users', 'id')->where('role', 'student'),
                ],
                'information_verified' => ['accepted'],
            ]);
            $request->validate([
                'details.days_absent' => ['required', 'integer', 'min:1', 'max:365'],
            ]);
            $daysAbsent = (int) $request->input('details.days_absent');
            $request->validate([
                'details.absence_dates' => ['required', 'array', 'size:'.$daysAbsent],
                'details.absence_dates.*' => ['required', 'date', 'distinct', 'before_or_equal:today'],
                'details.absence_reasons' => ['nullable', 'array', 'size:'.$daysAbsent],
                'details.absence_reasons.*' => ['required', 'string', Rule::in([
                    'class_attendance',
                    'psychological_testing',
                    'student_housing',
                    'academic_deficiencies',
                    'scholarship_grant_assistance',
                    'student_employment',
                    'other',
                ])],
                'details.absence_other_reasons' => ['nullable', 'array', 'size:'.$daysAbsent],
                'details.absence_other_reasons.*' => ['nullable', 'string', 'max:255'],
            ]);
            $absenceDates = $request->input('details.absence_dates');
            $absenceReasons = $request->input('details.absence_reasons');
            $absenceOtherReasons = $request->input('details.absence_other_reasons', []);

            // Keep older clients/forms working by applying the original single
            // referral cause to every date when per-date causes are not sent.
            if (count($absenceReasons ?? []) !== $daysAbsent) {
                $legacyReason = $request->input('details.referral_reasons.0');
                abort_unless(filled($legacyReason), 422, 'Choose a cause for every absence or tardy date.');
                $absenceReasons = array_fill(0, $daysAbsent, $legacyReason);
                $absenceOtherReasons = array_fill(0, $daysAbsent, $request->input('details.other_reason'));
            }

            foreach ($absenceReasons as $index => $reason) {
                if ($reason === 'other' && ! filled($absenceOtherReasons[$index] ?? null)) {
                    throw IlluminateValidationValidationException::withMessages([
                        'details.absence_other_reasons.'.$index => 'Please specify the other reason for this absence.',
                    ]);
                }
            }

            $request->merge([
                'details' => [
                    ...$request->input('details', []),
                    'referral_reasons' => [$absenceReasons[0]],
                    'other_reason' => $absenceOtherReasons[0] ?? null,
                    'date_absent' => $absenceDates[0],
                ],
            ]);
            $student = User::query()->findOrFail($validated['student_id']);
            $definition = SlipCatalog::all()[$slip];
            $data = $this->validatedData($request, $definition);
            $issuedAt = now()->toDateString();

            $data['student_id'] = $student->id_number;
            $data['student_name'] = $student->name;
            $data['request_date'] = $issuedAt;
            $data['sex'] = ucfirst($student->gender);
            $data['referred_by_name'] = $request->user()->name;
            $data['referred_date'] = $issuedAt;
            $data['received_by_name'] = null;
            $data['received_date'] = null;
            $data['document_code'] = null;
            $data['revision_number'] = null;
            $data['effectivity_date'] = null;
            $data['control_number'] = null;
            $data['submitted_by_teacher'] = true;
            $data['referred_student_id'] = $student->id;
            $data['referred_student_name'] = $student->name;
            $data['assigned_by'] = $request->user()->id;

            $referralGroups = [];
            foreach ($absenceDates as $index => $absenceDate) {
                $referralGroups[] = [
                    'reason' => $absenceReasons[$index],
                    'other_reason' => $absenceOtherReasons[$index] ?? null,
                    'dates' => [$absenceDate],
                ];
            }

            return DB::transaction(function () use ($request, $student, $slip, $data, $definition, $pdfGenerator, $referralGroups) {
                foreach ($referralGroups as $group) {
                    $referralData = [
                        ...$data,
                        'date_absent' => implode(', ', $group['dates']),
                        'absence_dates' => $group['dates'],
                        'days_absent' => count($group['dates']),
                        'referral_reasons' => [$group['reason']],
                        'other_reason' => $group['other_reason'],
                    ];
                    $receivedAt = now();
                    $assignment = SlipAssignment::create([
                        'assigned_by' => $request->user()->id,
                        'student_id' => $student->id,
                        'bundle_id' => (string) Str::uuid(),
                        'slip' => $slip,
                        'completed_at' => $receivedAt,
                    ]);
                    $referralData = [
                        ...$referralData,
                        ...$this->generatedReferralDocumentControlData($assignment),
                    ];

                    $pdfPath = 'slip-submissions/'.Str::uuid().'.pdf';
                    $submission = SlipSubmission::create([
                        'user_id' => $student->id,
                        'slip' => $slip,
                        'data' => $referralData,
                        'pdf_path' => $pdfPath,
                        // A teacher-created referral is routed to Guidance immediately.
                        'review_status' => 'submitted',
                    ]);

                    if (! Storage::disk('local')->put($pdfPath, $pdfGenerator->generate($submission, $definition))) {
                        throw new \RuntimeException('The referral draft PDF could not be stored.');
                    }

                    $assignment->update([
                        'slip_submission_id' => $submission->id,
                        'status' => 'submitted',
                    ]);
                }

                $cause = SlipCatalog::referralCauseText($referralData);
                $submissionUrl = route('slips.submissions.show', $submission);

                $this->notifyWorkflowParticipants(
                    User::query()->where('role', 'guidance_counselor')->get(),
                    'Student referral received',
                    'Teacher '.$request->user()->name.' sent a referral for '.$student->name.'. Cause: '.$cause.'.',
                    $submissionUrl,
                    'referral',
                    $assignment->id,
                );
                $this->notifyWorkflowParticipants(
                    [$student],
                    'Your teacher sent a referral',
                    'Your teacher, '.$request->user()->name.', sent a referral for you. Cause: '.$cause.'.',
                    $submissionUrl,
                    'referral',
                    $assignment->id,
                );

                return redirect()
                    ->route('teacher.dashboard')
                    ->with('status', count($referralGroups) === 1
                        ? 'The referral was sent to Guidance and the student.'
                        : count($referralGroups).' referrals were sent to Guidance and the student.');
            });
        }

        $validated = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('users', 'id')->where('role', 'student'),
            ],
        ]);

        SlipAssignment::create([
            'assigned_by' => $request->user()->id,
            'student_id' => $validated['student_id'],
            'slip' => $slip,
        ]);
        $student = User::query()->find($validated['student_id']);
        $this->notifyWorkflowParticipants(
            [$student],
            'Form assigned',
            'Guidance or a teacher assigned the '.$this->slipDisplayName($slip).' to you.',
            route('assigned-slips.index'),
            'slip',
        );

        return redirect()
            ->route('slips.index')
            ->with('status', 'The form was assigned to the selected student.');
    }

    public function cancelTeacherReferral(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        abort_unless($request->user()->role === 'teacher', 403);

        DB::transaction(function () use ($request, $slipAssignment) {
            $assignment = SlipAssignment::query()
                ->whereKey($slipAssignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (int) $assignment->assigned_by === (int) $request->user()->id
                    && $assignment->slip === 'student-referral-slip',
                404
            );
            abort_unless(
                in_array($assignment->status, ['pending', 'submitted'], true),
                409,
                'Only active referrals can be canceled.'
            );

            $submission = $assignment->slipSubmission;
            abort_unless(
                $submission !== null
                    && (bool) ($submission->data['submitted_by_teacher'] ?? false),
                409,
                'Only completed teacher-submitted referrals can be canceled.'
            );

            $submission->update([
                'review_status' => 'cancelled',
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $followups = SlipAssignment::query()
                ->where('related_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->get();

            foreach ($followups as $followup) {
                if ($followup->slipSubmission) {
                    $followup->slipSubmission->update([
                        'review_status' => 'cancelled',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                }

                $followup->update([
                    'status' => 'cancelled',
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }

            $assignment->update(['status' => 'cancelled']);
        });

        return redirect()
            ->route('teacher.dashboard')
            ->with('status', 'The referral was canceled. Its linked forms are no longer active.');
    }

    public function updateTeacherReferralNotes(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        abort_unless($request->user()->role === 'teacher', 403);
        abort_unless(
            (int) $slipAssignment->assigned_by === (int) $request->user()->id
                && $slipAssignment->slip === 'student-referral-slip',
            404
        );

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                $wordCount = preg_match_all('/\S+/u', (string) $value);

                if ($wordCount > 50) {
                    $fail('The comment may not exceed 50 words.');
                }
            }],
        ]);

        $slipAssignment->update(['notes' => filled($validated['notes'] ?? null) ? $validated['notes'] : null]);

        return back()->with('status', 'Referral comment saved.');
    }

    public function updateGuidanceReferralNotes(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless($slipAssignment->slip === 'student-referral-slip', 404);

        $validated = $request->validate([
            'guidance_notes' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                $wordCount = preg_match_all('/\S+/u', (string) $value);

                if ($wordCount > 50) {
                    $fail('The private note may not exceed 50 words.');
                }
            }],
        ]);

        $slipAssignment->update([
            'guidance_notes' => filled($validated['guidance_notes'] ?? null) ? $validated['guidance_notes'] : null,
        ]);

        return back()->with('status', 'Private note saved.');
    }

    public function create(Request $request, string $slip): View
    {
        abort_unless(isset(SlipCatalog::forRole($request->user()->role)[$slip]), 403);

        $catalog = SlipCatalog::forRole($request->user()->role);
        abort_unless(isset($catalog[$slip]), 404);

        $students = User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->get(['id', 'name', 'id_number', 'email', 'gender']);

        $studentCifs = SlipSubmission::query()
            ->whereIn('user_id', $students->pluck('id'))
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->get()
            ->unique('user_id')
            ->mapWithKeys(fn (SlipSubmission $submission) => [$submission->user_id => $submission->data?->getArrayCopy() ?? []]);

        $studentReferences = StudentReference::query()
            ->get(['id_number', 'year', 'course'])
            ->keyBy(fn (StudentReference $reference) => StudentReference::normalizeIdNumber($reference->id_number));

        if ($slip === 'student-referral-slip') {
            return view('slips.create-referral', [
                'title' => $catalog[$slip]['title'],
                'slip' => $catalog[$slip],
                'students' => $students,
                'studentReferences' => $studentReferences,
                'studentCifs' => $studentCifs,
                'selectAction' => route('slips.assign', $slip),
                'cancelUrl' => route('teacher.dashboard'),
                'today' => now()->toDateString(),
            ]);
        }

        return view('slips.select-student', [
            'title' => $catalog[$slip]['title'],
            'students' => $students,
            'selectAction' => route('slips.assign', $slip),
            'cancelUrl' => route('slips.index'),
        ]);
    }

    public function requestForm(Request $request, string $slip): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);
        abort_unless(isset(SlipCatalog::studentRequestForms()[$slip]), 404);

        $assignment = DB::transaction(function () use ($request, $slip) {
            User::query()
                ->whereKey($request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeRequestExists = SlipAssignment::query()
                ->where('student_id', $request->user()->id)
                ->where('slip', $slip)
                ->whereIn('status', ['pending', 'submitted'])
                ->exists();
            abort_unless(! $activeRequestExists, 409, 'You already have an active request for this form.');

            return SlipAssignment::create([
                'assigned_by' => $request->user()->id,
                'student_id' => $request->user()->id,
                'slip' => $slip,
            ]);
        });

        $this->notifyWorkflowParticipants(
            User::query()->where('role', 'guidance_counselor')->get()->all(),
            'Student form request',
            $request->user()->name.' requested a '.$this->slipDisplayName($slip).'.',
            route('guidance.slip-review').'#slip-assignment-'.$assignment->id,
            'slip',
            $assignment->id,
        );

        return redirect()->route('assigned-slips.fillup', $assignment);
    }

    public function cancelStudentSlipRequest(Request $request, SlipAssignment $slipAssignment): RedirectResponse
    {
        DB::transaction(function () use ($request, $slipAssignment): void {
            $assignment = SlipAssignment::query()
                ->whereKey($slipAssignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $request->user()->role === 'student'
                    && $assignment->student_id === $request->user()->id
                    && $assignment->assigned_by === $request->user()->id,
                403
            );
            abort_unless(isset(SlipCatalog::studentRequestForms()[$assignment->slip]), 404);
            abort_unless(
                $assignment->status === 'pending'
                    && $assignment->slip_submission_id === null,
                409,
                'Only unsubmitted requests can be canceled.'
            );

            $assignment->delete();
        });

        return redirect()
            ->route('student.dashboard')
            ->with('status', 'Your form request was canceled.');
    }

    public function issueFollowup(
        Request $request,
        SlipAssignment $referral,
        string $slip,
        SlipPdfGenerator $pdfGenerator
    ): RedirectResponse {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless(in_array($slip, [
            'class-admission-slip',
        ], true), 404);

        DB::transaction(function () use ($request, $referral, $slip, $pdfGenerator) {
            $lockedReferral = SlipAssignment::query()
                ->whereKey($referral->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $lockedReferral->slip === 'student-referral-slip'
                    && in_array($lockedReferral->status, ['submitted', 'approved'], true),
                409
            );

            $this->ensureFollowupFormMayBeIssued($slip, $lockedReferral);

            $referralSubmission = $lockedReferral->slipSubmission;
            abort_unless($referralSubmission !== null, 409, 'The submitted referral PDF could not be found.');
            $receivedAt = now()->toDateString();
            $referralSubmission->update([
                'data' => [
                    ...$referralSubmission->data->getArrayCopy(),
                    'received_by_name' => $request->user()->name,
                    'received_date' => $receivedAt,
                ],
            ]);

            $referralPdf = $pdfGenerator->generate(
                $referralSubmission->fresh(),
                SlipCatalog::all()['student-referral-slip']
            );
            if (! Storage::disk('local')->put($referralSubmission->pdf_path, $referralPdf)) {
                throw new \RuntimeException('The updated referral slip PDF could not be stored.');
            }

            SlipAssignment::create([
                'assigned_by' => $request->user()->id,
                'student_id' => $lockedReferral->student_id,
                'bundle_id' => $lockedReferral->bundle_id,
                'related_assignment_id' => $lockedReferral->id,
                'slip' => $slip,
            ]);
        });

        $this->notifyWorkflowParticipants(
            [
                $referral->student,
                $referral->assignedBy,
            ],
            'Class Admission Slip issued',
            'Guidance issued a Class Admission Slip for your referral.',
            route('assigned-slips.index'),
            'slip',
            $referral->id,
        );

        return redirect()
            ->route('guidance.dashboard')
            ->with('status', 'The referral receipt was recorded and the Class Admission Slip was sent to the student. The referral PDF will be available after its document-control details are issued.');
    }

    public function updateReferralDocumentControl(
        Request $request,
        SlipAssignment $referral,
        SlipPdfGenerator $pdfGenerator
    ): RedirectResponse {
        abort_unless($request->user()->role === 'guidance_counselor', 403);

        DB::transaction(function () use ($referral, $pdfGenerator) {
            $lockedReferral = SlipAssignment::query()
                ->whereKey($referral->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $lockedReferral->slip === 'student-referral-slip'
                    && in_array($lockedReferral->status, ['submitted', 'approved'], true),
                409,
                'Only referrals submitted by a student can receive document-control details.'
            );

            $referralSubmission = $lockedReferral->slipSubmission;
            abort_unless(
                $referralSubmission !== null && $this->isTeacherSubmittedReferral($lockedReferral),
                409,
                'The teacher-issued referral submission could not be found.'
            );
            $referralSubmission->update([
                'data' => [
                    ...$referralSubmission->data->getArrayCopy(),
                    ...$this->generatedReferralDocumentControlData($lockedReferral),
                ],
            ]);

            $referralPdf = $pdfGenerator->generate(
                $referralSubmission->fresh(),
                SlipCatalog::all()['student-referral-slip']
            );
            if (! Storage::disk('local')->put($referralSubmission->pdf_path, $referralPdf)) {
                throw new \RuntimeException('The referral slip PDF could not be stored after document-control details were issued.');
            }
        });

        return redirect()
            ->route('guidance.referrals')
            ->with('status', 'The referral document-control details were generated. The referral PDF is now available to the student and teacher.');
    }

    /**
     * @return array{document_code: string, revision_number: string, effectivity_date: string, control_number: string}
     */
    private function generatedReferralDocumentControlData(SlipAssignment $referral): array
    {
        $receivedAt = $referral->completed_at ?? now();

        return [
            'document_code' => 'STUDENT-REFERRAL-SLIP',
            'revision_number' => '00',
            'effectivity_date' => $receivedAt->toDateString(),
            'control_number' => sprintf('REF-%s-%06d', $receivedAt->format('Y'), $referral->id),
        ];
    }

    private function hasReferralDocumentControlData(array $data): bool
    {
        foreach (['document_code', 'revision_number', 'effectivity_date', 'control_number'] as $key) {
            if (! filled($data[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function hasReferralDocumentControlIdentifiers(array $data): bool
    {
        foreach (['document_code', 'revision_number', 'control_number'] as $key) {
            if (! filled($data[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function ensureFollowupFormMayBeIssued(string $slip, SlipAssignment $referral): void
    {
        if ($slip === 'class-admission-slip') {
            $latestAppointment = Appointment::query()
                ->where('referral_id', $referral->id)
                ->latest('created_at')
                ->first();

            abort_unless(
                $latestAppointment === null || $latestAppointment->status === 'done',
                409,
                'The Class Admission Slip is on hold until the referral counseling appointment is completed.'
            );
        }

        abort_unless(
            ! SlipAssignment::query()
                ->where('related_assignment_id', $referral->id)
                ->where('slip', $slip)
                ->exists(),
            409,
            'This form has already been issued for the referral.'
        );
    }

    public function editClientInformation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);

        $assignment = SlipAssignment::query()
            ->where('student_id', $request->user()->id)
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->first();

        if (! $assignment) {
            $assignment = SlipAssignment::create([
                'assigned_by' => $request->user()->id,
                'student_id' => $request->user()->id,
                'slip' => 'qf-bo-oss-04-client-information-form',
            ]);
        }

        return redirect()->route('assigned-slips.fillup', $assignment);
    }

    public function assignedCreate(Request $request, SlipAssignment $slipAssignment): View
    {
        abort_unless(
            $request->user()->role === 'student'
                && $slipAssignment->student_id === $request->user()->id,
            403
        );
        abort_unless(
            in_array($slipAssignment->status, ['pending', 'submitted'], true)
                || ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form' && $slipAssignment->status === 'approved'),
            403
        );

        $isTeacherSubmittedReferral = $this->isTeacherSubmittedReferral($slipAssignment);

        $student = $request->user();

        $catalog = SlipCatalog::all();
        abort_unless(isset($catalog[$slipAssignment->slip]), 404);

        $clientInformationDefaults = $slipAssignment->slip === 'qf-bo-oss-04-client-information-form'
            ? $this->clientInformationDefaults($student)
            : [];
        $defaults = $slipAssignment->slip === 'class-admission-slip'
            ? [...$this->studentDefaults($student), ...$this->classAdmissionReferralData($slipAssignment)]
            : ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form'
                ? [...$this->studentDefaults($student), ...$clientInformationDefaults, 'email' => $student->email]
                : $this->studentDefaults($student));

        if ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form') {
            foreach ($catalog[$slipAssignment->slip]['groups'] as &$group) {
                foreach ($group['fields'] as &$field) {
                    if ($field['key'] === 'college') {
                        unset($field['readonly']);
                    } elseif (filled($defaults[$field['key']] ?? null)) {
                        $field['readonly'] = true;
                    } else {
                        unset($field['readonly']);
                    }
                }
            }
            unset($group, $field);
        }

        return view('slips.fillup', [
            'slug' => $slipAssignment->slip,
            'slip' => $catalog[$slipAssignment->slip],
            'assignment' => $slipAssignment,
            'selectedStudent' => $student,
            'defaults' => $defaults,
            'existingSubmission' => $slipAssignment->slipSubmission,
            'isStudentRequest' => isset(SlipCatalog::studentRequestForms()[$slipAssignment->slip]),
            'isTeacherSubmittedReferral' => $isTeacherSubmittedReferral,
            'referralPdfReady' => $isTeacherSubmittedReferral
                ? $this->hasReferralDocumentControlData($slipAssignment->slipSubmission?->data?->getArrayCopy() ?? [])
                : true,
        ]);
    }

    public function assignedStore(
        Request $request,
        SlipAssignment $slipAssignment,
        SlipPdfGenerator $pdfGenerator,
        CifDocxSampleGenerator $docxGenerator
    ): RedirectResponse {
        abort_unless(
            $request->user()->role === 'student'
                && $slipAssignment->student_id === $request->user()->id,
            403
        );
        abort_unless(
            (in_array($slipAssignment->status, ['pending', 'submitted'], true)
                || ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form' && $slipAssignment->status === 'approved'))
                && $slipAssignment->slip !== 'slip_information',
            403
        );

        $catalog = SlipCatalog::all();
        abort_unless(isset($catalog[$slipAssignment->slip]), 404);
        $definition = $catalog[$slipAssignment->slip];
        $student = $request->user();

        if ($this->isTeacherSubmittedReferral($slipAssignment)) {
            abort_unless($slipAssignment->status === 'pending', 403);
            abort_unless($slipAssignment->slipSubmission !== null, 409);

            // Teacher-created referrals are routed directly to Guidance. Keep the
            // original teacher-entered values if an old pending record is viewed.
            $data = $slipAssignment->slipSubmission->data->getArrayCopy();
        } elseif ($slipAssignment->slip === 'class-admission-slip') {
            // Class Admission is auto-filled from the referral and CIF; the student
            // only submits an excuse and optional supporting evidence below.
            $data = [];
        } else {
            $data = $this->validatedData($request, $definition);
        }

        if ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form') {
            $cifData = $request->validate([
                'details.course' => ['required', Rule::in(array_keys(SlipCatalog::courseOptions()))],
                'details.year_level' => ['required', Rule::in(array_keys(SlipCatalog::yearLevelOptions()))],
                'details.contact_number' => ['required', 'digits:11'],
                'details.father_contact' => ['nullable', 'digits:11'],
                'details.mother_contact' => ['nullable', 'digits:11'],
                'details.guardian_contact' => ['nullable', 'digits:11'],
                'details.father_age' => ['nullable', 'integer', 'min:1', 'max:120'],
                'details.mother_age' => ['nullable', 'integer', 'min:1', 'max:120'],
                'details.parent_marital_status' => ['nullable', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Divorced'])],
            ])['details'];
            $college = SlipCatalog::courseCollegeMap()[$cifData['course']];

            $clientInformationDefaults = $this->clientInformationDefaults($student);
            $data = [
                ...$clientInformationDefaults,
                ...array_filter($data, fn ($value) => is_array($value) ? $value !== [] : filled($value)),
                'college' => $college,
                'course' => trim($cifData['course']),
                'year_level' => trim($cifData['year_level']),
                'course_year' => trim($cifData['course'].' '.$cifData['year_level']),
            ];
            if (filled($clientInformationDefaults['contact_number'] ?? null)) {
                $data['contact_number'] = $clientInformationDefaults['contact_number'];
            }
        }

        if (! $this->isTeacherSubmittedReferral($slipAssignment)) {
            foreach ($this->studentDefaults($student) as $key => $value) {
                if ($value !== null
                    && array_key_exists($key, $data)
                    && ! ($slipAssignment->slip === 'qf-bo-oss-04-client-information-form'
                        && in_array($key, ['college', 'course', 'year_level', 'course_year', 'program_year'], true))) {
                    $data[$key] = $value;
                }
            }
        }

        if ($slipAssignment->slip === 'class-admission-slip') {
            $excuseData = $request->validate([
                'details.student_excuse' => ['required', 'string', 'max:4000'],
            ]);
            $data = [
                ...$this->classAdmissionReferralData($slipAssignment),
                'student_excuse' => $excuseData['details']['student_excuse'],
            ];

            $existingData = $slipAssignment->slipSubmission?->data?->getArrayCopy() ?? [];
            $newAttachments = $this->validatedAttachments($request);
            if (($existingData['_attachments'] ?? []) !== [] || $newAttachments !== []) {
                $data['_attachments'] = [
                    ...($existingData['_attachments'] ?? []),
                    ...$newAttachments,
                ];
            }
        }

        $data['referred_student_id'] = $student->id;
        $data['referred_student_name'] = $student->name;
        $data['assigned_by'] = $slipAssignment->assigned_by;

        return $this->createSubmission(
            $request,
            $slipAssignment->slip,
            $data,
            $definition,
            $pdfGenerator,
            $docxGenerator,
            $slipAssignment,
            $slipAssignment->slipSubmission
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, array $definition): array
    {
        $rules = [];

        foreach ($definition['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $key = 'details.'.$field['key'];
                $presence = $field['required'] ? 'required' : 'nullable';

                if ($field['type'] === 'checkboxes') {
                    $arrayRules = $field['required'] ? ['min:1'] : [];
                    if ($field['key'] === 'referral_reasons') {
                        $arrayRules[] = 'max:1';
                    }
                    $rules[$key] = [$presence, 'array', ...$arrayRules];
                    $rules[$key.'.*'] = [
                        'string',
                        Rule::in(array_keys($field['options'])),
                    ];

                    continue;
                }

                $rules[$key] = match ($field['type']) {
                    'date' => [$presence, 'date'],
                    'number' => [$presence, 'integer', 'min:1', 'max:365'],
                    'select' => [$presence, 'string', Rule::in(array_keys($field['options']))],
                    default => [$presence, 'string', 'max:'.($field['type'] === 'textarea' ? 4000 : 255)],
                };
            }
        }

        $validated = $request->validate($rules);
        $input = $validated['details'] ?? [];
        $isOtherReferralReason = ($input['referral_reasons'] ?? []) === ['other'];

        if ($isOtherReferralReason && ! filled($input['other_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'details.other_reason' => 'Please specify the other reason for referral.',
            ]);
        }

        if (! $isOtherReferralReason) {
            $input['other_reason'] = null;
        }

        $data = [];

        foreach ($definition['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $data[$field['key']] = $input[$field['key']] ?? null;

                if ($field['key'] === 'request_date' && $data[$field['key']] === null) {
                    $data[$field['key']] = now()->toDateString();
                }
            }
        }

        return $data;
    }

    /**
     * Store optional evidence uploaded with a Class Admission Slip.
     *
     * @return array<int, array{original_name: string, path: string, mime_type: string|null, size: int}>
     */
    private function validatedAttachments(Request $request): array
    {
        $request->validate([
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            ],
        ]);

        $attachments = [];
        foreach ($request->file('attachments', []) as $file) {
            $storedName = Str::uuid().'.'.($file->extension() ?: 'bin');
            $path = $file->storeAs('slip-submissions/attachments', $storedName, 'local');

            if (! $path) {
                throw new \RuntimeException('An attachment could not be stored.');
            }

            $attachments[] = [
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validatedGuidanceData(Request $request, array $definition): array
    {
        $rules = [];

        foreach ($definition['guidance_fields'] as $field) {
            if ($field['type'] === 'system_generated') {
                continue;
            }

            $key = 'details.'.$field['key'];
            $presence = $field['required'] ? 'required' : 'nullable';

            if ($field['type'] === 'checkboxes') {
                $rules[$key] = [
                    $presence,
                    'string',
                    Rule::in(array_keys($field['options'])),
                ];

                continue;
            }

            $rules[$key] = match ($field['type']) {
                'date' => [$presence, 'date'],
                'select' => [$presence, 'string', Rule::in(array_keys($field['options']))],
                default => [$presence, 'string', 'max:255'],
            };
        }

        $validated = $request->validate($rules);
        $input = $validated['details'] ?? [];
        $otherActionSelected = ($input['actions_taken'] ?? null) === 'other_action';
        $otherActionDetail = $request->validate([
            'details.other_action_detail' => [
                $otherActionSelected ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
        ])['details']['other_action_detail'] ?? null;
        $data = [];

        foreach ($definition['guidance_fields'] as $field) {
            if ($field['type'] === 'system_generated') {
                continue;
            }

            $data[$field['key']] = $field['key'] === 'other_action_detail'
                ? ($otherActionSelected ? $otherActionDetail : null)
                : ($input[$field['key']] ?? null);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function classAdmissionReferralData(SlipAssignment $assignment): array
    {
        $referral = $assignment->relatedAssignment;
        abort_unless(
            $referral !== null
                && $referral->slip === 'student-referral-slip'
                && $referral->student_id === $assignment->student_id
                && in_array($referral->status, ['submitted', 'approved'], true)
                && $referral->slipSubmission !== null,
            409,
            'The Class Admission Slip needs a completed Teacher Referral Slip.'
        );

        $referralData = $referral->slipSubmission->data->getArrayCopy();
        $student = User::query()->find($assignment->student_id);
        $cifData = $this->latestClientInformationData($student);
        $reference = StudentReference::query()
            ->whereIn('id_number_lookup_hash', array_filter(array_map(
                fn (string $id): ?string => SensitiveLookup::hash('id_number', $id),
                StudentReference::matchingIdNumbers($student?->id_number),
            )))
            ->first();
        $absenceDates = $referralData['absence_dates'] ?? [];
        $dateAbsent = $referralData['date_absent'] ?? (is_array($absenceDates) ? implode(', ', $absenceDates) : $absenceDates);
        $daysAbsent = $referralData['days_absent'] ?? (is_array($absenceDates) ? count($absenceDates) : null);

        return [
            'referral_slip_number' => sprintf(
                'SR-%s-%06d',
                $referral->created_at?->format('Y') ?? now()->format('Y'),
                $referral->id
            ),
            'date_received' => $referralData['received_date']
                ?? $referral->completed_at?->toDateString()
                ?? $referral->created_at?->toDateString()
                ?? now()->toDateString(),
            'student_id' => $cifData['student_id'] ?? $student?->id_number,
            'student_name' => $cifData['student_name'] ?? $referralData['student_name'] ?? $student?->name,
            'sex' => $cifData['sex']
                ?? ($reference?->gender
                    ? ucfirst($reference->gender)
                    : ($referralData['sex'] ?? ($student?->gender ? ucfirst($student->gender) : null))),
            'program_year' => $cifData['course_year']
                ?? trim(implode(' ', array_filter([$reference?->course, $reference?->year])))
                ?: ($referralData['program_year'] ?? $referralData['course_year'] ?? null),
            'date_absent' => $dateAbsent,
            'days_absent' => $daysAbsent,
            'absence_causes' => $referralData['referral_reasons'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $definition
     */
    private function createSubmission(
        Request $request,
        string $slip,
        array $data,
        array $definition,
        SlipPdfGenerator $pdfGenerator,
        CifDocxSampleGenerator $docxGenerator,
        ?SlipAssignment $assignment = null,
        ?SlipSubmission $existingSubmission = null,
        ?int $submissionUserId = null
    ): RedirectResponse {
        $pdfPath = $slip === 'qf-bo-oss-04-client-information-form'
            ? 'slip-submissions/'.Str::uuid().'.docx'
            : ($existingSubmission?->pdf_path ?? 'slip-submissions/'.Str::uuid().'.pdf');

        try {
            $submission = DB::transaction(function () use ($request, $slip, $data, $definition, $pdfGenerator, $docxGenerator, $pdfPath, $assignment, $existingSubmission, $submissionUserId) {
                $lockedAssignment = null;

                if ($assignment) {
                    $lockedAssignment = SlipAssignment::query()
                        ->whereKey($assignment->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless(
                        in_array($lockedAssignment->status, ['pending', 'submitted'], true)
                            || ($slip === 'qf-bo-oss-04-client-information-form' && $lockedAssignment->status === 'approved'),
                        409
                    );
                }

                if ($existingSubmission) {
                    $submission = SlipSubmission::query()
                        ->whereKey($existingSubmission->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless(
                        $submission->review_status !== 'approved'
                            || $slip === 'qf-bo-oss-04-client-information-form',
                        409
                    );
                    $submission->update([
                        'data' => $data,
                        'pdf_path' => $pdfPath,
                        'review_status' => 'submitted',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                } else {
                    $submission = SlipSubmission::create([
                        'user_id' => $submissionUserId ?? $request->user()->id,
                        'slip' => $slip,
                        'data' => $data,
                        'pdf_path' => $pdfPath,
                        'review_status' => 'submitted',
                    ]);
                }

                $document = $slip === 'qf-bo-oss-04-client-information-form'
                    ? $docxGenerator->generate($data, [], $request->user()->name)
                    : $pdfGenerator->generate($submission->fresh(), $definition);

                if (! Storage::disk('local')->put($pdfPath, $document)) {
                    throw new \RuntimeException('The completed slip document could not be stored.');
                }

                if ($lockedAssignment) {
                    $assignmentUpdates = [
                        'status' => 'submitted',
                        'slip_submission_id' => $submission->id,
                        'completed_at' => now(),
                        'approved_by' => null,
                        'approved_at' => null,
                    ];
                    if (
                        $lockedAssignment->assigned_by === $lockedAssignment->student_id
                        && in_array($slip, array_keys(SlipCatalog::studentRequestForms()), true)
                    ) {
                        $assignmentUpdates['counselor_viewed_at'] = null;
                    }
                    $lockedAssignment->update($assignmentUpdates);
                }

                return $submission;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($pdfPath);
            throw $exception;
        }

        if ($slip === 'qf-bo-oss-04-client-information-form') {
            return redirect()->route('slips.submissions.show', $submission);
        }

        $submissionUrl = route('slips.submissions.show', $submission);
        $isStudentFormRequest = $request->user()->role === 'student'
            && $assignment !== null
            && $assignment->assigned_by === $assignment->student_id
            && in_array($slip, array_keys(SlipCatalog::studentRequestForms()), true);

        if ($isStudentFormRequest) {
            $this->notifyWorkflowParticipants(
                User::query()->where('role', 'guidance_counselor')->get(),
                'Student form submitted',
                $submission->user->name.' sent a '.$this->slipDisplayName($slip).'.',
                $submissionUrl,
                'slip-submitted',
                $submission->id,
            );
            $this->notifyWorkflowParticipants(
                [$submission->user],
                'Form submitted',
                'Your '.$this->slipDisplayName($slip).' was sent to Guidance and is awaiting review.',
                $submissionUrl,
                'slip',
                $submission->id,
            );
        } else {
            $participants = [
                $submission->user,
                ...User::query()->where('role', 'guidance_counselor')->get()->all(),
            ];
            if ($assignment?->assignedBy !== null) {
                $participants[] = $assignment->assignedBy;
            }
            $this->notifyWorkflowParticipants(
                $participants,
                'Form submitted',
                'The '.$this->slipDisplayName($slip).' was submitted and is awaiting review.',
                $submissionUrl,
                'slip',
                $submission->id,
            );
        }

        return redirect()->route('slips.submissions.show', $submission);
    }

    private function isTeacherSubmittedReferral(SlipAssignment $assignment): bool
    {
        return $assignment->slip === 'student-referral-slip'
            && (bool) ($assignment->slipSubmission?->data['submitted_by_teacher'] ?? false);
    }

    /**
     * @param  iterable<int, User|null>  $participants
     */
    private function notifyWorkflowParticipants(
        iterable $participants,
        string $title,
        string $message,
        string $url,
        string $activityType,
        ?int $activityId = null,
    ): void {
        $notifiedIds = [];

        foreach ($participants as $participant) {
            if (! $participant instanceof User || isset($notifiedIds[$participant->id])) {
                continue;
            }

            $notifiedIds[$participant->id] = true;

            try {
                $participant->notify(new WorkflowActivity(
                    $title,
                    $message,
                    $url,
                    $activityType,
                    $activityId,
                ));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function slipDisplayName(string $slip): string
    {
        return SlipCatalog::all()[$slip]['title'] ?? 'form';
    }

    public function show(Request $request, SlipSubmission $slipSubmission): View
    {
        $this->authorizeSubmission($request, $slipSubmission);
        $slips = SlipCatalog::all();
        abort_unless(isset($slips[$slipSubmission->slip]), 404);
        $assignment = SlipAssignment::query()
            ->where('slip_submission_id', $slipSubmission->id)
            ->first();

        if (
            $request->user()->role === 'guidance_counselor'
            && $assignment !== null
            && $assignment->assigned_by === $assignment->student_id
            && in_array($assignment->slip, array_keys(SlipCatalog::studentRequestForms()), true)
        ) {
            $firstView = DB::transaction(function () use ($assignment): bool {
                $lockedAssignment = SlipAssignment::query()
                    ->whereKey($assignment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedAssignment->counselor_viewed_at !== null) {
                    return false;
                }

                $lockedAssignment->update(['counselor_viewed_at' => now()]);

                return true;
            });

            if ($firstView) {
                $this->notifyWorkflowParticipants(
                    [$assignment->student],
                    'Your form was seen by Guidance',
                    'The '.$this->slipDisplayName($assignment->slip).' was seen by the counselor. Please stand by for an update.',
                    route('slips.submissions.show', $slipSubmission),
                    'form-viewed',
                    $slipSubmission->id,
                );
            }
        }

        $guidanceData = $slipSubmission->data?->getArrayCopy() ?? [];
        if ($request->user()->role === 'guidance_counselor'
            && $slipSubmission->slip === 'class-admission-slip'
            && $assignment !== null) {
            $guidanceData = [
                ...$guidanceData,
                ...$this->classAdmissionReferralData($assignment),
            ];
        }

        return view('slips.submitted', [
            'submission' => $slipSubmission,
            'guidanceData' => $guidanceData,
            'slip' => $slips[$slipSubmission->slip],
            'guidanceFields' => $request->user()->role === 'guidance_counselor'
                ? ($slips[$slipSubmission->slip]['guidance_fields'] ?? [])
                : [],
            'assignment' => $assignment,
            'referralPdfReady' => $slipSubmission->slip !== 'student-referral-slip'
                || $this->hasReferralDocumentControlData($slipSubmission->data?->getArrayCopy() ?? []),
        ]);
    }

    public function notifyStudentOfUpdate(Request $request, SlipSubmission $slipSubmission): RedirectResponse
    {
        abort_unless($request->user()->role === 'guidance_counselor', 403);
        abort_unless(in_array($slipSubmission->slip, array_keys(SlipCatalog::studentRequestForms()), true), 404);

        $assignment = SlipAssignment::query()
            ->where('slip_submission_id', $slipSubmission->id)
            ->where('student_id', $slipSubmission->user_id)
            ->whereColumn('assigned_by', 'student_id')
            ->firstOrFail();
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $this->notifyWorkflowParticipants(
            [$assignment->student],
            'Update from Guidance Counselor',
            'Guidance Counselor update on your '.$this->slipDisplayName($slipSubmission->slip).': '.trim($validated['message']),
            route('slips.submissions.show', $slipSubmission),
            'form-update',
            $slipSubmission->id,
        );

        return redirect()
            ->route('slips.submissions.show', $slipSubmission)
            ->with('status', 'Your update was sent to the student.');
    }

    public function attachment(Request $request, SlipSubmission $slipSubmission, int $attachment): StreamedResponse
    {
        $this->authorizeSubmission($request, $slipSubmission);

        $submissionData = $slipSubmission->data?->getArrayCopy() ?? [];
        $attachments = array_values($submissionData['_attachments'] ?? []);
        abort_unless(isset($attachments[$attachment]), 404);

        $file = $attachments[$attachment];
        abort_unless(is_array($file) && Storage::disk('local')->exists($file['path'] ?? ''), 404);

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($file['original_name'] ?? 'attachment'))) ?: 'attachment';

        return Storage::disk('local')->response(
            $file['path'],
            $filename,
            [
                'Content-Type' => $file['mime_type'] ?? 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]
        );
    }

    public function pdf(Request $request, SlipSubmission $slipSubmission, SlipPdfGenerator $pdfGenerator): Response|StreamedResponse
    {
        $this->authorizeSubmission($request, $slipSubmission);
        if (
            $slipSubmission->slip === 'student-referral-slip'
            && ! $this->hasReferralDocumentControlData($slipSubmission->data?->getArrayCopy() ?? [])
        ) {
            DB::transaction(function () use ($slipSubmission, $pdfGenerator) {
                $referral = SlipAssignment::query()
                    ->where('slip_submission_id', $slipSubmission->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless(
                    $this->isTeacherSubmittedReferral($referral),
                    409,
                    'Only teacher-submitted referrals can receive generated document-control details.'
                );

                $submission = $referral->slipSubmission;
                abort_unless($submission !== null, 404);
                $submission->update([
                    'data' => [
                        ...$submission->data->getArrayCopy(),
                        ...$this->generatedReferralDocumentControlData($referral),
                    ],
                ]);

                $pdf = $pdfGenerator->generate(
                    $submission->fresh(),
                    SlipCatalog::all()['student-referral-slip']
                );
                if (! Storage::disk('local')->put($submission->pdf_path, $pdf)) {
                    throw new \RuntimeException('The generated referral PDF could not be stored for download.');
                }
            });
            $slipSubmission->refresh();
        }
        if ($slipSubmission->slip === 'qf-bo-oss-04-client-information-form') {
            return response($pdfGenerator->generate(
                $slipSubmission,
                SlipCatalog::all()['qf-bo-oss-04-client-information-form']
            ))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="'.$slipSubmission->slip.'-'.$slipSubmission->id.'.pdf"')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        abort_unless(Storage::disk('local')->exists($slipSubmission->pdf_path), 404);

        return Storage::disk('local')->response(
            $slipSubmission->pdf_path,
            "{$slipSubmission->slip}-{$slipSubmission->id}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$slipSubmission->slip.'-'.$slipSubmission->id.'.pdf"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }

    public function systemPreview(Request $request, SlipSubmission $slipSubmission): View
    {
        $this->authorizeSubmission($request, $slipSubmission);
        $slips = SlipCatalog::all();
        abort_unless(isset($slips[$slipSubmission->slip]), 404);

        return view('slips.system-preview', [
            'slip' => $slips[$slipSubmission->slip],
            'previewData' => $slipSubmission->data?->getArrayCopy() ?? [],
        ]);
    }

    public function docx(
        Request $request,
        SlipSubmission $slipSubmission,
        CifDocxSampleGenerator $cifDocxGenerator,
        SlipSubmissionDocxGenerator $slipDocxGenerator
    ): Response {
        $this->authorizeSubmission($request, $slipSubmission);

        if (in_array($slipSubmission->slip, ['student-referral-slip', 'class-admission-slip'], true)) {
            $data = $slipSubmission->data?->getArrayCopy() ?? [];

            if ($slipSubmission->slip === 'class-admission-slip') {
                $controlDate = $slipSubmission->approved_at ?? $slipSubmission->created_at ?? now();
                $data = [
                    ...$data,
                    'document_code' => 'CLASS-ADMISSION-SLIP',
                    'revision_number' => '00',
                    'effectivity_date' => $controlDate->toDateString(),
                    'control_number' => sprintf('CAS-%s-%06d', $controlDate->format('Y'), $slipSubmission->id),
                ];
            } elseif (! $this->hasReferralDocumentControlData($data)) {
                DB::transaction(function () use ($slipSubmission) {
                    $referral = SlipAssignment::query()
                        ->where('slip_submission_id', $slipSubmission->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless(
                        $this->isTeacherSubmittedReferral($referral),
                        409,
                        'Only teacher-submitted referrals can receive generated document-control details.'
                    );

                    $submission = $referral->slipSubmission;
                    abort_unless($submission !== null, 404);
                    $submission->update([
                        'data' => [
                            ...$submission->data->getArrayCopy(),
                            ...$this->generatedReferralDocumentControlData($referral),
                        ],
                    ]);
                });
                $slipSubmission->refresh();
                $data = $slipSubmission->data?->getArrayCopy() ?? [];
            }

            $filename = $slipSubmission->slip.'-'.$slipSubmission->id.'-filled.docx';

            return response($slipDocxGenerator->generate($slipSubmission->slip, $data))
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        abort_unless($slipSubmission->slip === 'qf-bo-oss-04-client-information-form', 404);

        $appointments = Appointment::query()
            ->with('reviewer')
            ->where(function ($query) use ($slipSubmission) {
                $query->where('user_id', $slipSubmission->user_id)
                    ->orWhereHas('referral', fn ($referralQuery) => $referralQuery->where('student_id', $slipSubmission->user_id));
            })
            ->where('status', 'done')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'date' => ($appointment->scheduled_at ?? $appointment->completed_at)?->format('m/d/Y'),
                'concern' => $appointment->concerns ?? $appointment->purpose,
                'action' => $appointment->action_taken,
                'counselor' => $appointment->reviewer?->name ?? $request->user()->name,
            ])
            ->all();

        return response($cifDocxGenerator->generate(
            $slipSubmission->data?->getArrayCopy() ?? [],
            $appointments,
            $request->user()->name,
        ))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="cif-'.$slipSubmission->id.'-filled.docx"');
    }

    public function previewPdf(Request $request, SlipSubmission $slipSubmission): StreamedResponse
    {
        $this->authorizeSubmission($request, $slipSubmission);
        abort_unless($slipSubmission->slip === 'student-referral-slip', 404);
        abort_unless(Storage::disk('local')->exists($slipSubmission->pdf_path), 404);

        return Storage::disk('local')->response(
            $slipSubmission->pdf_path,
            'referral-preview.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }

    private function authorizeSubmission(Request $request, SlipSubmission $submission): void
    {
        $assignment = SlipAssignment::query()
            ->with('relatedAssignment')
            ->where('slip_submission_id', $submission->id)
            ->first();

        $teacherOwnsReferral = $request->user()->role === 'teacher'
            && (
                ($assignment?->slip === 'student-referral-slip'
                    && (int) $assignment->assigned_by === (int) $request->user()->id)
                || ($assignment?->slip === 'class-admission-slip'
                    && (int) $assignment->relatedAssignment?->assigned_by === (int) $request->user()->id)
            );

        abort_unless(
            $submission->user_id === $request->user()->id
                || $request->user()->role === 'guidance_counselor'
                || $teacherOwnsReferral,
            403
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function studentDefaults(User $student): array
    {
        $cifData = $this->latestClientInformationData($student);
        $nameParts = preg_split('/\s+/', trim($student->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = array_shift($nameParts) ?? '';
        $lastName = count($nameParts) > 0 ? array_pop($nameParts) : '';
        $middleName = implode(' ', $nameParts);
        $cifMiddleName = $cifData['middle_name'] ?? null;

        return [
            'student_id' => $cifData['student_id'] ?? $student->id_number,
            'student_name' => $cifData['student_name'] ?? $student->name,
            'email' => $student->email,
            'first_name' => $cifData['first_name'] ?? $firstName,
            'last_name' => $cifData['last_name'] ?? $lastName,
            'middle_name' => $cifMiddleName ?? ($middleName ?: null),
            'middle_initial' => $cifMiddleName
                ? mb_substr($cifMiddleName, 0, 1).'.'
                : ($middleName !== '' ? mb_substr($middleName, 0, 1).'.' : null),
            'sex' => $cifData['sex'] ?? ($student->gender ? ucfirst($student->gender) : null),
            'course' => $cifData['course'] ?? null,
            'year_level' => $cifData['year_level'] ?? null,
            'course_year' => $cifData['course_year'] ?? null,
            'program_year' => $cifData['course_year'] ?? null,
            'college' => SlipCatalog::canonicalCollege($cifData['college'] ?? null),
            'request_date' => now()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function latestClientInformationData(?User $student): array
    {
        if (! $student) {
            return [];
        }

        return SlipSubmission::query()
            ->where('user_id', $student->id)
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->first()?->data?->getArrayCopy() ?? [];
    }

    /** @return array<string, mixed> */
    private function clientInformationDefaults(User $student): array
    {
        $reference = StudentReference::query()
            ->whereIn('id_number_lookup_hash', array_filter(array_map(
                fn (string $id): ?string => SensitiveLookup::hash('id_number', $id),
                StudentReference::matchingIdNumbers($student->id_number),
            )))
            ->first();
        if (! $reference) {
            return [];
        }
        $source = collect($reference?->source_data ?? [])->mapWithKeys(function ($value, $key) {
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string) $key)) ?? '';

            return [$normalized => $value];
        });
        $sourceValue = static function (array $keys) use ($source): ?string {
            foreach ($keys as $key) {
                $value = $source->get($key);

                if (filled($value)) {
                    return trim((string) $value);
                }
            }

            return null;
        };
        $nameParts = preg_split('/\s+/', trim($reference?->name ?? $student->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $sourceValue(['firstname', 'givenname']) ?? (array_shift($nameParts) ?? '');
        $lastName = $sourceValue(['lastname', 'surname']) ?? (count($nameParts) ? array_pop($nameParts) : '');
        $middleName = $sourceValue(['middlename', 'middleinitial']) ?? implode(' ', $nameParts);
        $contactNumber = $sourceValue([
            'contactno',
            'contactnumber',
            'phonenumber',
            'mobilenumber',
            'mobilephonenumber',
            'mobilephone',
            'cellphonenumber',
            'cellphone',
            'cellnumber',
            'telephonenumber',
            'telephone',
            'phone',
            'mobile',
        ]);

        return array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'middle_name' => $middleName ?: null,
            'student_id' => $reference?->id_number ?? $student->id_number,
            'sex' => $reference?->gender ?? $student->gender,
            'course' => $reference?->course,
            'year_level' => $reference?->year,
            'contact_number' => $contactNumber,
            'email' => $student->email,
        ], fn ($value) => filled($value));
    }
}
