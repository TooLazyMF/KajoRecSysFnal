<?php

namespace Tests\Feature;

use App\Models\SlipAssignment;
use App\Models\SlipInformation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlipInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_view_another_users_slip_information(): void
    {
        $owner = User::factory()->create([
            'id_number' => 'STU-CNS-1002',
            'gender' => 'female',
            'role' => 'student',
        ]);
        $otherStudent = User::factory()->create([
            'id_number' => 'STU-CNS-1003',
            'gender' => 'male',
            'role' => 'student',
        ]);
        $slipInformation = SlipInformation::create([
            'user_id' => $owner->id,
            'full_name' => $owner->name,
            'last_name' => null,
            'first_name' => null,
            'student_id' => 'STU-CNS-1002',
            'email' => $owner->email,
            'degree' => 'BSCrim',
            'year_level' => 'First',
            'school_year' => '2025-2026',
            'purpose' => 'scholarship_application',
            'pdf_path' => 'cns-requests/test.pdf',
        ]);

        $this->actingAs($otherStudent)
            ->get(route('slip-informations.show', $slipInformation))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('slip-informations.show', $slipInformation))
            ->assertOk()
            ->assertSee('Slip Information Submitted');

        $this->get(route('slip-informations.system-preview', $slipInformation))
            ->assertOk()
            ->assertSee('<h1 class="text-xl font-bold">Slip Information</h1>', false);
    }

    public function test_rename_migration_preserves_slip_information_and_assignment_references(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $slipInformation = SlipInformation::create([
            'user_id' => $student->id,
            'full_name' => $student->name,
            'student_id' => 'STU-RENAME-1001',
            'email' => $student->email,
            'degree' => 'BSCrim',
            'year_level' => 'First',
            'school_year' => '2025-2026',
            'purpose' => 'scholarship_application',
            'pdf_path' => 'slip-informations/test.pdf',
        ]);
        $assignment = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'cns',
            'slip_information_id' => $slipInformation->id,
        ]);
        DB::table('backup_import_mappings')->insert([
            'backup_id' => '00000000-0000-4000-8000-000000000001',
            'table_name' => 'slip_informations',
            'source_record_id' => 900,
            'target_record_id' => $slipInformation->id,
        ]);

        $migration = require database_path('migrations/2026_11_12_104200_rename_cns_requests_to_slip_informations.php');
        $migration->down();

        $this->assertDatabaseHas('cns_requests', ['id' => $slipInformation->id]);
        $this->assertDatabaseHas('backup_import_mappings', ['table_name' => 'cns_requests']);
        $this->assertDatabaseHas('slip_assignments', [
            'id' => $assignment->id,
            'cns_request_id' => $slipInformation->id,
        ]);

        $migration->up();

        $this->assertDatabaseHas('slip_informations', ['id' => $slipInformation->id]);
        $this->assertDatabaseHas('backup_import_mappings', ['table_name' => 'slip_informations']);
        $this->assertDatabaseHas('slip_assignments', [
            'id' => $assignment->id,
            'slip_information_id' => $slipInformation->id,
        ]);
        $this->assertTrue($assignment->fresh()->slipInformation->is($slipInformation));
    }

    public function test_legacy_cns_assignments_cannot_be_filled_or_submitted(): void
    {
        $teacher = User::factory()->create([
            'id_number' => 'TEACHER-CNS-3001',
            'gender' => 'female',
            'role' => 'teacher',
        ]);
        $student = User::factory()->create([
            'id_number' => 'STU-CNS-3001',
            'gender' => 'male',
            'role' => 'student',
        ]);
        $assignment = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'cns',
        ]);

        $this->actingAs($student)
            ->get(route('assigned-slips.fillup', $assignment))
            ->assertNotFound();
        $this->actingAs($student)
            ->post("/assigned-slips/{$assignment->id}/cns-submit", [])
            ->assertNotFound();

        $this->assertDatabaseCount('slip_informations', 0);
    }
}