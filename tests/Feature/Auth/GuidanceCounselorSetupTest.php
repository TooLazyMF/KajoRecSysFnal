<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\EmailOtp;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GuidanceCounselorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuidanceCounselorSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'guidance.initial_counselor.email' => 'adminexample@gmail.com',
            'guidance.initial_counselor.password' => 'TemporaryCounselorPassword123',
        ]);
    }

    public function test_database_seeder_creates_the_guidance_account_without_a_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, User::query()->where('role', 'guidance_counselor')->count());
    }

    public function test_seeded_counselor_logs_in_with_temporary_credentials_and_must_complete_setup(): void
    {
        $this->seed(GuidanceCounselorSeeder::class);
        $this->seed(GuidanceCounselorSeeder::class);

        $counselor = User::query()->where('role', 'guidance_counselor')->firstOrFail();
        $this->assertSame('Administrator', $counselor->name);
        $this->assertSame('adminexample@gmail.com', $counselor->email);
        $this->assertTrue($counselor->must_change_password);
        $this->assertTrue(Hash::check('TemporaryCounselorPassword123', $counselor->password));
        $this->assertSame(1, User::query()->where('role', 'guidance_counselor')->count());

        $this->post(route('login.store'), [
            'email' => 'adminexample@gmail.com',
            'password' => 'TemporaryCounselorPassword123',
            'share_location' => '1',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertRedirect(route('counselor.profile.edit'));

        $this->assertAuthenticatedAs($counselor);

        $this->get(route('guidance.dashboard'))
            ->assertRedirect(route('counselor.profile.edit'));

        $this->get(route('counselor.profile.edit'))
            ->assertOk()
            ->assertSee('Complete your guidance counselor account')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="name"', false);
    }

    public function test_seeder_configures_the_existing_placeholder_counselor(): void
    {
        User::forceCreate([
            'name' => 'Administrator',
            'email' => null,
            'id_number' => null,
            'gender' => null,
            'role' => 'guidance_counselor',
            'password' => 'Admin',
            'must_change_password' => true,
        ]);

        $this->seed(GuidanceCounselorSeeder::class);

        $counselor = User::query()->where('role', 'guidance_counselor')->firstOrFail();
        $this->assertSame('adminexample@gmail.com', $counselor->email);
        $this->assertTrue(Hash::check('TemporaryCounselorPassword123', $counselor->password));
        $this->assertTrue($counselor->must_change_password);
        $this->assertSame(1, User::query()->where('role', 'guidance_counselor')->count());
    }

    public function test_counselor_must_change_name_email_and_password_before_continuing(): void
    {
        Notification::fake();
        $this->seed(GuidanceCounselorSeeder::class);
        $counselor = User::query()->where('role', 'guidance_counselor')->firstOrFail();
        $this->actingAs($counselor);

        $this->put(route('counselor.profile.update'), [
            'name' => 'Administrator',
            'email' => 'counselor@example.com',
            'password' => 'SecureCounselor123',
            'password_confirmation' => 'SecureCounselor123',
        ])->assertSessionHasErrors('name');

        $this->put(route('counselor.profile.update'), [
            'name' => 'Alex Counselor',
            'email' => 'counselor@example.com',
            'password' => 'Admin',
            'password_confirmation' => 'Admin',
        ])->assertSessionHasErrors('password');

        $this->put(route('counselor.profile.update'), [
            'name' => 'Alex Counselor',
            'email' => 'counselor@example.com',
            'password' => 'SecureCounselor123',
            'password_confirmation' => 'SecureCounselor123',
        ])->assertRedirect(route('verification.notice'));

        $counselor->refresh();
        $this->assertSame('Alex Counselor', $counselor->name);
        $this->assertSame('counselor@example.com', $counselor->email);
        $this->assertFalse($counselor->must_change_password);
        $this->assertFalse($counselor->hasVerifiedEmail());
        $this->assertTrue(Hash::check('SecureCounselor123', $counselor->password));
        Notification::assertSentTo($counselor, EmailOtp::class);

        $this->get(route('guidance.dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_temporary_credentials_stop_working_after_counselor_setup(): void
    {
        Notification::fake();
        $this->seed(GuidanceCounselorSeeder::class);
        $counselor = User::query()->where('role', 'guidance_counselor')->firstOrFail();

        $this->actingAs($counselor)
            ->put(route('counselor.profile.update'), [
                'name' => 'Alex Counselor',
                'email' => 'alex.counselor@example.com',
                'password' => 'SecureCounselor123',
                'password_confirmation' => 'SecureCounselor123',
            ])
            ->assertRedirect(route('verification.notice'));

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'adminexample@gmail.com',
            'password' => 'TemporaryCounselorPassword123',
            'share_location' => '1',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => 'alex.counselor@example.com',
            'password' => 'SecureCounselor123',
            'share_location' => '1',
            'login_latitude' => '14.5995',
            'login_longitude' => '120.9842',
        ])->assertRedirect(route('guidance.dashboard'));
        $this->get(route('guidance.dashboard'))
            ->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($counselor);
    }
}
