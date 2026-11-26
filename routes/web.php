<?php

use App\Http\Controllers\AccountActivityController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\DataBackupController;
use App\Http\Controllers\EmailVerificationOtpController;
use App\Http\Controllers\GuidanceCounselorProfileController;
use App\Http\Controllers\LoginTwoFactorOtpController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetOtpController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SlipInformationController;
use App\Http\Controllers\SlipSubmissionController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentRecordController;
use App\Http\Controllers\TeacherAccountRequestController;
use App\Http\Controllers\TeacherProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::view('/', 'welcome')->name('home');
Route::post('/', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['guest', 'throttle:login'])
    ->name('login.root');
Route::post('login/location', fn () => response()->noContent())
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login.location');
Route::post('register', [RegistrationController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('register.store');
Route::get('teacher-registration/{teacherAccountRequest}/verify', [TeacherAccountRequestController::class, 'verify'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('teacher-registration.verify');

Route::view('forgot-password', 'pages::auth.forgot-password')->name('password.request');
Route::post('forgot-password', [PasswordResetOtpController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('password.email');
Route::post('reset-password', [PasswordResetOtpController::class, 'reset'])
    ->middleware('throttle:5,1')
    ->name('password.update');

Route::get('registration/verify-email', [EmailVerificationOtpController::class, 'registrationNotice'])
    ->middleware('guest')
    ->name('registration.verification.notice');
Route::post('email/verification-otp', [EmailVerificationOtpController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('verification.otp.send');
Route::post('email/verification-otp/verify', [EmailVerificationOtpController::class, 'verify'])
    ->middleware('throttle:5,1')
    ->name('verification.otp.verify');
Route::post('email/verification-otp/cancel', [EmailVerificationOtpController::class, 'cancelRegistration'])
    ->name('registration.cancel');
Route::get('teacher-account/verify/{user}', [UserManagementController::class, 'verifyCreatedTeacher'])
    ->middleware('signed')
    ->name('teacher-account.verify');

Route::middleware(['guest', 'throttle:two-factor'])->group(function () {
    Route::post('two-factor-challenge/email', [LoginTwoFactorOtpController::class, 'send'])
        ->name('two-factor.email.send');
    Route::post('two-factor-challenge/email/verify', [LoginTwoFactorOtpController::class, 'verify'])
        ->name('two-factor.email.verify');
});

Route::post('logout', [AccountActivityController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'role:teacher'])->group(function () {
    Route::get('teacher/complete-profile', [TeacherProfileController::class, 'edit'])
        ->name('teacher.profile.edit');
    Route::put('teacher/complete-profile', [TeacherProfileController::class, 'update'])
        ->name('teacher.profile.update');
});

Route::middleware(['auth', 'role:guidance_counselor'])->group(function () {
    Route::get('guidance/complete-profile', [GuidanceCounselorProfileController::class, 'edit'])
        ->name('counselor.profile.edit');
    Route::put('guidance/complete-profile', [GuidanceCounselorProfileController::class, 'update'])
        ->name('counselor.profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('api')->name('api.')->group(function () {
        Route::post('push/subscribe', [PushNotificationController::class, 'subscribe'])
            ->name('push.subscribe');
        Route::post('push/test', [PushNotificationController::class, 'test'])
            ->name('push.test');
    });

    Route::post('account-activity', [AccountActivityController::class, 'touch'])
        ->name('account-activity.touch');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])
        ->name('notifications.open');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');

    Route::get('dashboard', function () {
        // Students enter their dedicated dashboard; other roles use the shared role view.
        if (auth()->user()->role === 'student') {
            return redirect()->route('student.dashboard');
        }

        return view('dashboard');
    })->name('dashboard');

    Route::get('student-dashboard', StudentDashboardController::class)->name('student.dashboard');
    Route::get('teacher-dashboard', [SlipSubmissionController::class, 'teacherDashboard'])->name('teacher.dashboard');
    Route::get('teacher/referral-history', [SlipSubmissionController::class, 'teacherReferralHistory'])
        ->name('teacher.referral-history');
    Route::get('teacher/referrals-sent', [SlipSubmissionController::class, 'teacherReferralsSent'])
        ->name('teacher.referrals-sent');
    Route::get('teacher/students', [SlipSubmissionController::class, 'teacherStudents'])
        ->name('teacher.students');
    Route::post('teacher/referrals/{slipAssignment}/acknowledge', [SlipSubmissionController::class, 'acknowledgeTeacherReferral'])
        ->name('teacher.referrals.acknowledge');
    Route::get('guidance-dashboard', [SlipSubmissionController::class, 'guidanceDashboard'])->name('guidance.dashboard');
    Route::get('guidance/referrals', [SlipSubmissionController::class, 'guidanceReferrals'])->name('guidance.referrals');
    Route::get('guidance/cif', [SlipSubmissionController::class, 'guidanceCifIndex'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.cif.index');
    Route::get('guidance/cif/{slipSubmission}/docx', [SlipSubmissionController::class, 'guidanceCifDocx'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.cif.docx');
    Route::get('guidance/cif/{slipSubmission}', [SlipSubmissionController::class, 'guidanceCifShow'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.cif.show');
    Route::get('guidance/referrals/sample-cif.docx', [SlipSubmissionController::class, 'sampleClientInformationDocx'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.sample-cif-docx');
    Route::get('guidance/referrals/sample-cif.pdf', [SlipSubmissionController::class, 'sampleClientInformationPdf'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.sample-cif-pdf');
    Route::get('guidance/referrals/sample-cif.docx/preview', [SlipSubmissionController::class, 'sampleClientInformationDocxPreview'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.sample-cif-docx-preview');
    Route::get('guidance/referrals/{referral}/cif.docx', [SlipSubmissionController::class, 'clientInformationDocx'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.cif-docx');
    Route::get('guidance/referrals/{referral}/cif.docx/preview', [SlipSubmissionController::class, 'clientInformationDocxPreview'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.cif-docx-preview');
    Route::get('guidance/record-history/{type}', [StudentRecordController::class, 'index'])
        ->whereIn('type', ['all', 'slips', 'appointments'])
        ->name('guidance.records.index');
    Route::get('guidance/students-records', [StudentRecordController::class, 'dashboard'])
        ->name('guidance.students-records');
    Route::get('guidance/archived-files', [StudentRecordController::class, 'archived'])
        ->name('guidance.records.archived');
    Route::get('guidance/student-records/{student}', [StudentRecordController::class, 'show'])
        ->name('guidance.records.show');
    Route::get('guidance/monthly-report', [StudentRecordController::class, 'monthlyReport'])
        ->name('guidance.reports.monthly');

    Route::middleware('role:guidance_counselor')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('teachers/requests/{teacherAccountRequest}/id-photo', [TeacherAccountRequestController::class, 'showIdPhoto'])
            ->name('teacher-requests.photo');
        Route::post('teachers/requests/{teacherAccountRequest}/approve', [TeacherAccountRequestController::class, 'approve'])
            ->name('teacher-requests.approve');
        Route::post('teachers/requests/{teacherAccountRequest}/decline', [TeacherAccountRequestController::class, 'decline'])
            ->name('teacher-requests.decline');
        Route::get('teachers/{user}/edit', [UserManagementController::class, 'editTeacher'])->name('teachers.edit');
        Route::put('teachers/{user}', [UserManagementController::class, 'updateTeacher'])->name('teachers.update');
        Route::post('students/import', [UserManagementController::class, 'importStudents'])->name('students.import');
        Route::get('data-backup', [DataBackupController::class, 'download'])->name('data-backup.download');
        Route::post('data-backup/import', [DataBackupController::class, 'import'])->name('data-backup.import');
        Route::post('users/{user}/status', [UserManagementController::class, 'updateStatus'])
            ->name('users.status');
    });

    Route::middleware('role:student,guidance_counselor')->group(function () {
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::post('appointments/{appointment}/accept', [AppointmentController::class, 'accept'])->name('appointments.accept');
        Route::post('appointments/{appointment}/referral-confirmation', [AppointmentController::class, 'confirmReferralProposal'])
            ->middleware('role:student')
            ->name('appointments.referral.confirm');
        Route::post('appointments/{appointment}/referral-suggestion', [AppointmentController::class, 'suggestReferralTime'])
            ->middleware('role:student')
            ->name('appointments.referral.suggest');
        Route::post('guidance/referrals/{referral}/appoint', [AppointmentController::class, 'appointFromReferral'])
            ->middleware('role:guidance_counselor')
            ->name('guidance.referrals.appoint');
        Route::patch('guidance/referrals/{referral}/appointments/{appointment}', [AppointmentController::class, 'updateFromReferral'])
            ->middleware('role:guidance_counselor')
            ->name('guidance.referrals.appointments.update');
        Route::post('appointments/{appointment}/complete', [AppointmentController::class, 'complete'])
            ->middleware('role:guidance_counselor')
            ->name('appointments.complete');
        Route::post('appointments/{appointment}/missed', [AppointmentController::class, 'markMissed'])
            ->middleware('role:guidance_counselor')
            ->name('appointments.missed');
        Route::patch('appointments/{appointment}/action-taken', [AppointmentController::class, 'updateActionTaken'])
            ->middleware('role:guidance_counselor')
            ->name('appointments.action-taken.update');
        Route::get('appointments/{appointment}/attachments/{attachment}', [AppointmentController::class, 'downloadAttachment'])
            ->whereNumber('attachment')
            ->name('appointments.attachments.download');
        Route::post('appointments/{appointment}/decline', [AppointmentController::class, 'decline'])->name('appointments.decline');
        Route::post('appointments/{appointment}/reappoint', [AppointmentController::class, 'reappoint'])->name('appointments.reappoint');
    });

    Route::get('guidance-slips', [SlipSubmissionController::class, 'index'])->name('slips.index');
    Route::get('guidance-slips/{slip}/fill-up', [SlipSubmissionController::class, 'create'])->name('slips.fillup');
    Route::post('guidance-slips/{slip}/assign', [SlipSubmissionController::class, 'assign'])->name('slips.assign');
    Route::post('teacher/referrals/{slipAssignment}/cancel', [SlipSubmissionController::class, 'cancelTeacherReferral'])
        ->name('teacher.referrals.cancel');
    Route::patch('teacher/referrals/{slipAssignment}/notes', [SlipSubmissionController::class, 'updateTeacherReferralNotes'])
        ->name('teacher.referrals.notes');
    Route::patch('guidance/referrals/{slipAssignment}/private-notes', [SlipSubmissionController::class, 'updateGuidanceReferralNotes'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.private-notes');
    Route::post('student/slip-requests/{slip}', [SlipSubmissionController::class, 'requestForm'])
        ->name('student.slip-requests.store');
    Route::post('guidance/referrals/{referral}/issue/{slip}', [SlipSubmissionController::class, 'issueFollowup'])
        ->name('guidance.slip-review.issue');
    Route::post('guidance/referrals/{referral}/document-control', [SlipSubmissionController::class, 'updateReferralDocumentControl'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.referrals.document-control');

    Route::get('assigned-slips', [SlipSubmissionController::class, 'assignedIndex'])->name('assigned-slips.index');
    Route::get('assigned-slips/{slipAssignment}/fill-up', [SlipSubmissionController::class, 'assignedCreate'])
        ->name('assigned-slips.fillup');
    Route::post('assigned-slips/{slipAssignment}/cancel-request', [SlipSubmissionController::class, 'cancelStudentSlipRequest'])
        ->name('assigned-slips.cancel-request');
    Route::post('assigned-slips/{slipAssignment}/submit', [SlipSubmissionController::class, 'assignedStore'])
        ->name('assigned-slips.submit');
    Route::post('assigned-slips/{slipAssignment}/send-result-to-teacher', [SlipSubmissionController::class, 'sendClassAdmissionResultToTeacher'])
        ->name('assigned-slips.send-result-to-teacher');
    Route::get('slip-history', [SlipSubmissionController::class, 'history'])->name('slip-history.index');
    Route::get('guidance/slip-review', [SlipSubmissionController::class, 'reviewIndex'])
        ->name('guidance.slip-review');
    Route::post('guidance/slip-submissions/{slipSubmission}/notify-student', [SlipSubmissionController::class, 'notifyStudentOfUpdate'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.slip-submissions.notify-student');
    Route::post('guidance/slip-review/{slipAssignment}/approve', [SlipSubmissionController::class, 'approve'])
        ->middleware('role:guidance_counselor')
        ->name('guidance.slip-review.approve');

    Route::get('slip-submissions/{slipSubmission}', [SlipSubmissionController::class, 'show'])
        ->name('slips.submissions.show');
    Route::get('slip-submissions/{slipSubmission}/pdf', [SlipSubmissionController::class, 'pdf'])
        ->name('slips.submissions.pdf');
    Route::get('slip-submissions/{slipSubmission}/docx', [SlipSubmissionController::class, 'docx'])
        ->name('slips.submissions.docx');
    Route::get('slip-submissions/{slipSubmission}/preview', [SlipSubmissionController::class, 'previewPdf'])
        ->name('slips.submissions.preview-pdf');
    Route::get('slip-submissions/{slipSubmission}/system-preview', [SlipSubmissionController::class, 'systemPreview'])
        ->name('slips.submissions.system-preview');
    Route::get('slip-submissions/{slipSubmission}/attachments/{attachment}', [SlipSubmissionController::class, 'attachment'])
        ->whereNumber('attachment')
        ->name('slips.submissions.attachment');
    Route::get('slip-informations/{slipInformation}', [SlipInformationController::class, 'show'])->name('slip-informations.show');
    Route::get('slip-informations/{slipInformation}/pdf', [SlipInformationController::class, 'pdf'])->name('slip-informations.pdf');
    Route::get('slip-informations/{slipInformation}/system-preview', [SlipInformationController::class, 'systemPreview'])
        ->name('slip-informations.system-preview');
});

require __DIR__.'/settings.php';
