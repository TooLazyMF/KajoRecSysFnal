<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\SlipAssignment;
use App\Models\SlipSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentRecordsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_records_only_exposes_approved_slips_and_completed_appointments(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create([
            'name' => 'Registered Student',
            'id_number' => 'RECORD-001',
            'role' => 'student',
            'is_active' => false,
            'is_graduated' => true,
        ]);
        SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'qf-bo-oss-04-client-information-form',
            'data' => ['student_name' => 'Registered Student'],
            'pdf_path' => 'client-information-form.docx',
            'review_status' => 'approved',
        ]);
        $referralSubmission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'student-referral-slip',
            'data' => [
                'document_code' => 'DOC-1',
                'revision_number' => '1',
                'effectivity_date' => now()->toDateString(),
                'control_number' => 'CTRL-1',
            ],
            'pdf_path' => 'student-referral-slip.pdf',
            'review_status' => 'approved',
        ]);
        $referral = SlipAssignment::create([
            'assigned_by' => $counselor->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'status' => 'approved',
            'slip_submission_id' => $referralSubmission->id,
            'completed_at' => now(),
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'referral_id' => $referral->id,
            'scheduled_at' => now()->addDays(2),
            'purpose' => 'Counselor-scheduled referral appointment',
            'status' => 'accepted',
        ]);
        $classAdmission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'class-admission-slip',
            'data' => ['student_name' => 'Registered Student'],
            'pdf_path' => 'class-admission-slip.pdf',
            'review_status' => 'approved',
        ]);
        SlipAssignment::create([
            'assigned_by' => $counselor->id,
            'student_id' => $student->id,
            'related_assignment_id' => $referral->id,
            'slip' => 'class-admission-slip',
            'status' => 'approved',
            'slip_submission_id' => $classAdmission->id,
            'completed_at' => now(),
        ]);
        $draftSubmission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'class-admission-slip',
            'data' => ['private_draft_value' => 'Draft slip data'],
            'pdf_path' => 'draft-class-admission.pdf',
            'review_status' => 'submitted',
        ]);
        SlipAssignment::create([
            'assigned_by' => $counselor->id,
            'student_id' => $student->id,
            'slip' => 'class-admission-slip',
            'status' => 'submitted',
            'slip_submission_id' => $draftSubmission->id,
            'completed_at' => now(),
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subDay(),
            'purpose' => 'Completed counseling session',
            'status' => 'done',
            'completed_at' => now(),
            'action_taken' => 'Reviewed study plan',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->addDay(),
            'purpose' => 'Pending counseling request',
            'status' => 'accepted',
        ]);

        $this->actingAs($counselor)
            ->get(route('guidance.dashboard'))
            ->assertOk()
            ->assertDontSee('Open Students Records')
            ->assertDontSee('students-records-modal');

        $response = $this->actingAs($counselor)
            ->get(route('guidance.students-records'))
            ->assertOk()
            ->assertSee('Students Records')
            ->assertSee('Registered Student')
            ->assertSee('CIF')
            ->assertSee('Referrals and Class admission')
            ->assertSee('Student Referral Slip')
            ->assertSee('Class Admission Slip')
            ->assertSee('Scheduled by counselor')
            ->assertSee('Completed counseling session')
            ->assertDontSee('Draft slip data');

        preg_match('/data-appointments="([^"]*)"/', $response->getContent(), $appointmentsMatch);
        $appointments = json_decode(
            html_entity_decode($appointmentsMatch[1] ?? '', ENT_QUOTES | ENT_HTML5),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $appointments);
        $this->assertSame('Completed counseling session', $appointments[0]['purpose']);
    }

    public function test_record_buttons_open_modals_even_when_a_student_has_no_records(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create(['name' => 'Student Without Records', 'role' => 'student']);

        $response = $this->actingAs($counselor)
            ->get(route('guidance.students-records'))
            ->assertOk()
            ->assertSee('data-cif-modal="student-cif-modal-'.$student->id.'"', false)
            ->assertSee('No filled Client Information Form is available for this student yet.')
            ->assertSee('No completed appointments are available for this student yet.')
            ->assertSee('No completed referral or class admission slips are available for this student yet.');

        preg_match_all(
            '/<button\b[^>]*(?:data-cif-modal|data-appointments|data-slip-records)[^>]*>/',
            $response->getContent(),
            $recordButtons,
        );

        $this->assertCount(3, $recordButtons[0]);
        foreach ($recordButtons[0] as $button) {
            $this->assertStringNotContainsString('disabled', $button);
        }
    }

    public function test_cif_modal_shows_latest_filled_form_and_appointment_table_without_pdf(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create([
            'name' => 'CIF Modal Student',
            'role' => 'student',
        ]);
        SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'qf-bo-oss-04-client-information-form',
            'data' => [
                'first_name' => 'CIF Filled First Name',
                'student_id' => $student->id_number,
                'contact_number' => '555-1234',
                'consent' => ['agree'],
            ],
            'pdf_path' => 'client-information-form.pdf',
            'review_status' => 'submitted',
        ]);
        Appointment::create([
            'user_id' => $student->id,
            'scheduled_at' => now()->subDay(),
            'purpose' => 'CIF modal session concern',
            'concerns' => 'Discuss attendance',
            'status' => 'done',
            'completed_at' => now(),
            'action_taken' => 'Created attendance plan',
            'reviewed_by' => $counselor->id,
        ]);

        $response = $this->actingAs($counselor)
            ->get(route('guidance.students-records'))
            ->assertOk()
            ->assertSee('CIF Filled First Name')
            ->assertSee('dark:bg-neutral-900 dark:text-neutral-100', false)
            ->assertSee('bg-emerald-700', false)
            ->assertSee('Page 1 · Client Information')
            ->assertSee('Page 2 · Appointment Records')
            ->assertSee('data-cif-page="1"', false)
            ->assertSee('data-cif-page="2"', false)
            ->assertSee('Date')
            ->assertSee('Concerns')
            ->assertSee('Interventions / Actions Taken')
            ->assertSee('Attending Counselor')
            ->assertSee('Discuss attendance')
            ->assertSee('Created attendance plan')
            ->assertSee($counselor->name);

        $this->assertStringNotContainsString('data-cif-url=', $response->getContent());
    }

    public function test_students_records_page_is_only_available_to_guidance_counselors(): void
    {
        $student = User::factory()->create([
            'name' => 'Referral Test Student',
            'role' => 'student',
        ]);

        $this->actingAs($student)
            ->get(route('guidance.students-records'))
            ->assertForbidden();
    }

    public function test_received_referrals_shows_student_and_downloadable_referral_details(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $student = User::factory()->create(['role' => 'student']);
        $referralSubmission = SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'student-referral-slip',
            'data' => ['student_name' => $student->name],
            'pdf_path' => 'referral-preview.pdf',
            'review_status' => 'approved',
        ]);
        SlipAssignment::create([
            'assigned_by' => $counselor->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'status' => 'approved',
            'slip_submission_id' => $referralSubmission->id,
            'completed_at' => now(),
        ]);
        SlipSubmission::create([
            'user_id' => $student->id,
            'slip' => 'qf-bo-oss-04-client-information-form',
            'data' => ['student_name' => $student->name],
            'pdf_path' => 'client-information-form.docx',
            'review_status' => 'approved',
        ]);

        $this->actingAs($counselor)
            ->get(route('guidance.referrals'))
            ->assertOk()
            ->assertDontSee('Review submissions')
            ->assertDontSee('View filled CIF')
            ->assertDontSee('Preview referral form')
            ->assertSee('aria-label="View referral details for '.$student->name.'"', false)
            ->assertSee('aria-label="Download completed referral PDF for '.$student->name.'"', false);
    }
}
