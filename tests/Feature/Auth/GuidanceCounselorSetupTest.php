<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\EmailOtp;
use Database\Seeders\GuidanceCounselorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuidanceCounselorSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_counselor_logs_in_with_temporary_credentials_and_must_complete_setup(): void
    {
        $this->seed(GuidanceCounselorSeeder::class);
        $this->seed(GuidanceCounselorSeeder::class);

        $counselor = User::query()->where('role', 'guidance_counselor')->firstOrFail();
        $this->assertSame('Administrator', $counselor->name);
        $this->assertNull($counselor->email);
        $this->assertTrue($counselor->must_change_password);
        $this->assertTrue(Hash::check('Admin', $counselor->password));
        $this->assertSame(1, User::query()->where('role', 'guidance_counselor')->count());

        $this->post(route('login.store'), [
            'email' => 'Administrator',
            'password' => 'Admin',
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
            'email' => 'Administrator',
            'password' => 'Admin',
        ])->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => 'alex.counselor@example.com',
            'password' => 'SecureCounselor123',
        ])->assertRedirect(route('guidance.dashboard'));
        $this->get(route('guidance.dashboard'))
            ->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($counselor);
    }
}
