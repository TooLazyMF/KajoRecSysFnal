<?php

namespace Tests\Feature;

use App\Models\SlipAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('data-system-theme', false)
            ->assertSee('bg-[#f6f5f1]', false)
            ->assertSee('dark:bg-[#111712]', false)
            ->assertSee('bg-[#09ba44]', false)
            ->assertSee('dark:bg-[#20783e]', false);
    }

    public function test_asset_urls_use_the_forwarded_https_scheme(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
        ])->get('/');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/href="https:\/\/[^"]+\/build\/assets\/app-[^"]+\.css"/',
            $response->getContent(),
        );
    }

    public function test_guidance_dashboard_monthly_report_uses_same_origin_and_loads(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);

        $this->actingAs($counselor)
            ->get(route('guidance.dashboard'))
            ->assertOk()
            ->assertSee('Welcome to KAJOREC')
            ->assertSee('Generate monthly report')
            ->assertSee('rounded-3xl border border-emerald-200 bg-gradient-to-r', false)
            ->assertSee('flex snap-x gap-3 overflow-x-auto pb-2 sm:grid', false)
            ->assertSee('Guidance quick actions')
            ->assertSee('Manage Users')
            ->assertSee('src="/guidance/monthly-report?embedded=1"', false);

        $this->get(route('guidance.reports.monthly'))
            ->assertOk()
            ->assertSee('Monthly Student Report')
            ->assertSee('name="month"', false)
            ->assertDontSee('Workspace')
            ->assertDontSee('Guidance Dashboard');
    }

    public function test_guidance_can_save_encrypted_private_referral_notes_not_visible_to_teachers(): void
    {
        $counselor = User::factory()->create(['role' => 'guidance_counselor']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $referral = SlipAssignment::create([
            'assigned_by' => $teacher->id,
            'student_id' => $student->id,
            'slip' => 'student-referral-slip',
            'status' => 'submitted',
        ]);
        $note = 'Confidential counseling follow-up';

        $this->actingAs($counselor)
            ->get(route('guidance.dashboard'))
            ->assertOk()
            ->assertSee('Private Notes')
            ->assertSee(route('guidance.referrals.private-notes', $referral), false);

        $this->patch(route('guidance.referrals.private-notes', $referral), [
            'guidance_notes' => $note,
        ])->assertRedirect(route('guidance.dashboard'))
            ->assertSessionHas('status', 'Private note saved.');

        $storedNote = DB::table('slip_assignments')
            ->where('id', $referral->id)
            ->value('guidance_notes');
        $this->assertIsString($storedNote);
        $this->assertStringNotContainsString($note, $storedNote);
        $this->assertSame($note, $referral->fresh()->guidance_notes);

        $this->actingAs($teacher)
            ->get(route('teacher.referrals-sent'))
            ->assertOk()
            ->assertDontSee($note);

        $this->patch(route('guidance.referrals.private-notes', $referral), [
            'guidance_notes' => 'Unauthorized update',
        ])->assertRedirect(route('teacher.dashboard'));
        $this->assertSame($note, $referral->fresh()->guidance_notes);
    }
}
