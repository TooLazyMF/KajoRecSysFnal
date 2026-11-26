<?php

namespace Tests\Feature;

use App\Models\AccountSessionLog;
use App\Models\User;
use App\Services\AccountSessionLogger;
use App\Support\SensitiveLookup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSessionLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_successful_login_and_logout_are_recorded_with_required_browser_location(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
        ])->post(route('login.store'), [
            'email' => $student->email,
            'password' => 'password',
            'share_location' => '1',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertSessionHasNoErrors();

        $log = AccountSessionLog::query()->where('user_id', $student->id)->sole();
        $this->assertSame('Chrome on Windows', $log->device_name);
        $this->assertSame('14.5995', $log->latitude);
        $this->assertSame('120.9842', $log->longitude);
        $this->assertNull($log->logged_out_at);
        $this->assertDatabaseHas('sessions', [
            'id' => $log->session_hash,
            'user_id' => $student->id,
        ]);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertNotNull($log->fresh()->logged_out_at);
        $this->assertGuest();
    }

    public function test_login_requires_location_consent_and_valid_coordinates(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->post(route('login.store'), [
            'email' => $student->email,
            'password' => 'password',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertSessionHasErrors('share_location');

        $this->post(route('login.store'), [
            'email' => $student->email,
            'password' => 'password',
            'share_location' => '1',
        ])->assertSessionHasErrors('share_location');

        $this->assertGuest();
        $this->assertDatabaseMissing('account_session_logs', ['user_id' => $student->id]);
    }

    public function test_landing_page_login_also_records_the_required_location(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->post(route('login.root'), [
            'email' => $student->email,
            'password' => 'password',
            'share_location' => '1',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertRedirect(route('student.dashboard', absolute: false));

        $log = AccountSessionLog::query()->where('user_id', $student->id)->sole();
        $this->assertSame('14.5995', $log->latitude);
        $this->assertSame('120.9842', $log->longitude);
    }

    public function test_user_can_end_another_active_device_session(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('account-logs.index'))->assertOk();

        $remoteSessionHash = SensitiveLookup::hash('session_id', 'remote-device-session')
            ?? throw new \LogicException('A session ID hash could not be generated.');
        DB::table('sessions')->insert([
            'id' => $remoteSessionHash,
            'user_id' => $student->id,
            'payload' => 'encrypted-session-payload',
            'last_activity' => now()->timestamp,
        ]);
        $remoteLog = $student->accountSessionLogs()->create([
            'session_hash' => $remoteSessionHash,
            'device_name' => 'Firefox on Linux',
            'logged_in_at' => now()->subHour(),
            'last_activity_at' => now(),
        ]);

        $this->get(route('account-logs.index'))
            ->assertOk()
            ->assertSee('Log out this device');

        $this->post(route('account-logs.logout-device', $remoteLog))
            ->assertRedirect(route('account-logs.index'))
            ->assertSessionHas('status', 'The selected device was logged out.');

        $this->assertDatabaseMissing('sessions', ['id' => $remoteSessionHash]);
        $this->assertNotNull($remoteLog->fresh()->logged_out_at);

        $this->withSession(['account_session_log_id' => $remoteLog->id])
            ->actingAs($student)
            ->get(route('student.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'This device was logged out from your account.');
        $this->assertGuest();
    }

    public function test_user_can_log_out_the_current_device_from_account_logs(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('account-logs.index'))->assertOk();
        $log = AccountSessionLog::query()->where('user_id', $student->id)->sole();

        $this->post(route('account-logs.logout-device', $log))
            ->assertRedirect('/')
            ->assertSessionHas('status', 'This device was logged out.');

        $this->assertGuest();
        $this->assertNotNull($log->fresh()->logged_out_at);
    }

    public function test_user_cannot_log_out_another_users_device(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);
        $otherSessionHash = SensitiveLookup::hash('session_id', 'other-users-session')
            ?? throw new \LogicException('A session ID hash could not be generated.');
        DB::table('sessions')->insert([
            'id' => $otherSessionHash,
            'user_id' => $otherUser->id,
            'payload' => 'encrypted-session-payload',
            'last_activity' => now()->timestamp,
        ]);
        $otherLog = $otherUser->accountSessionLogs()->create([
            'session_hash' => $otherSessionHash,
            'device_name' => 'Firefox on Linux',
            'logged_in_at' => now()->subHour(),
            'last_activity_at' => now(),
        ]);

        $this->actingAs($student)
            ->post(route('account-logs.logout-device', $otherLog))
            ->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $otherSessionHash]);
        $this->assertNull($otherLog->fresh()->logged_out_at);
    }

    public function test_all_account_roles_can_view_monthly_paginated_logs(): void
    {
        foreach (['student', 'teacher', 'guidance_counselor'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            for ($index = 0; $index < 51; $index++) {
                $user->accountSessionLogs()->create([
                    'session_hash' => hash('sha256', $role.'-'.$index),
                    'device_name' => 'Test Browser',
                    'logged_in_at' => now()->subMinutes($index),
                    'last_activity_at' => now()->subMinutes($index),
                    'logged_out_at' => now()->subMinutes($index - 1),
                ]);
            }

            $this->actingAs($user)
                ->get(route('account-logs.index', ['month' => now()->format('Y-m')]))
                ->assertOk()
                ->assertSee('Account Logs')
                ->assertSee('Device Name')
                ->assertSee('Location')
                ->assertSee('Logged In')
                ->assertSee('Last Activity')
                ->assertSee('Logged Out')
                ->assertSee('Action')
                ->assertViewHas('logs', fn ($logs) => $logs->count() === 50 && $logs->total() >= 51);

            $this->get(route('account-logs.index', ['month' => now()->subMonth()->format('Y-m')]))
                ->assertOk()
                ->assertViewHas('logs', fn ($logs) => $logs->total() === 0);
        }
    }

    public function test_inactive_session_is_logged_out_after_ten_minutes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('profile.edit'))
            ->assertOk();
        $log = AccountSessionLog::query()->where('user_id', $student->id)->sole();
        $lastActivity = $log->fresh()->last_activity_at;

        $this->travel(10)->minutes();
        $this->get(route('student.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'You were logged out after 10 minutes of inactivity.');

        $this->assertGuest();
        $this->assertSame(
            $lastActivity->addMinutes(AccountSessionLogger::IDLE_TIMEOUT_MINUTES)->timestamp,
            $log->fresh()->logged_out_at->timestamp
        );
    }

    public function test_user_activity_extends_the_ten_minute_session_timeout(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->actingAs($student)->get(route('profile.edit'))->assertOk();

        $this->travel(9)->minutes();
        $this->post(route('account-activity.touch'))->assertNoContent();

        $this->travel(9)->minutes();
        $response = $this->get(route('profile.edit'));
        $this->assertSame(200, $response->status(), $response->headers->get('Location') ?? '');
        $this->assertAuthenticatedAs($student);
    }

    public function test_scheduled_idle_session_cleanup_records_the_ten_minute_logout_time(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $lastActivity = CarbonImmutable::now()->subMinutes(11);
        $log = $student->accountSessionLogs()->create([
            'session_hash' => hash('sha256', 'scheduled-idle-session'),
            'device_name' => 'Test Browser',
            'logged_in_at' => $lastActivity->subHour(),
            'last_activity_at' => $lastActivity,
        ]);

        $this->artisan('account-sessions:close-idle')->assertExitCode(0);

        $this->assertSame(
            $lastActivity->addMinutes(AccountSessionLogger::IDLE_TIMEOUT_MINUTES)->timestamp,
            $log->fresh()->logged_out_at->timestamp
        );
    }
}
