<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\EmailOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_student_profile_has_a_client_information_form_editor(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Client Information Form')
            ->assertSee('Edit CIF');

        $this->actingAs($student)
            ->get(route('profile.client-information.edit'))
            ->assertRedirect();

        $this->assertDatabaseHas('slip_assignments', [
            'assigned_by' => $student->id,
            'student_id' => $student->id,
            'slip' => 'qf-bo-oss-04-client-information-form',
        ]);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create([
            'name' => 'Example User',
            'email' => 'user@example.com',
        ]);

        Notification::fake();
        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('beginProfileUpdate')
            ->call('confirmProfileUpdate')
            ->set('password', 'password')
            ->call('verifyPasswordAndSendOtp');

        $response->assertHasNoErrors()
            ->assertSet('showOtpModal', true);

        $this->assertSame('Example User', $user->refresh()->name);
        $this->assertSame('user@example.com', $user->email);
        Notification::assertSentOnDemand(EmailOtp::class);

        Cache::put(
            'email-otp:profile-update:'.$user->id,
            ['code_hash' => Hash::make('123456')],
            now()->addMinutes(30),
        );

        $response->set('otp', '123456')
            ->call('verifyOtpAndUpdateProfile')
            ->assertHasNoErrors();
        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_profile_change_requires_the_current_password_before_sending_an_otp(): void
    {
        $user = User::factory()->create([
            'name' => 'Example User',
            'email' => 'user@example.com',
        ]);
        Notification::fake();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('name', 'Changed Name')
            ->set('email', 'user@example.com')
            ->call('beginProfileUpdate')
            ->call('confirmProfileUpdate')
            ->set('password', 'wrong-password')
            ->call('verifyPasswordAndSendOtp')
            ->assertHasErrors(['password']);

        $this->assertSame('Example User', $user->refresh()->name);
        Notification::assertNothingSent();
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('beginProfileUpdate')
            ->call('confirmProfileUpdate')
            ->set('password', 'password')
            ->call('verifyPasswordAndSendOtp');

        $response->assertHasNoErrors();
        Cache::put(
            'email-otp:profile-update:'.$user->id,
            ['code_hash' => Hash::make('123456')],
            now()->addMinutes(30),
        );

        $response->set('otp', '123456')
            ->call('verifyOtpAndUpdateProfile')
            ->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertSame('Test User', $user->name);
    }

    public function test_profile_is_not_updated_when_the_otp_is_incorrect(): void
    {
        $user = User::factory()->create([
            'name' => 'Example User',
            'email' => 'user@example.com',
        ]);
        Notification::fake();
        Cache::put(
            'email-otp:profile-update:'.$user->id,
            ['code_hash' => Hash::make('123456')],
            now()->addMinutes(30),
        );

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('name', 'Changed Name')
            ->set('email', 'user@example.com')
            ->call('beginProfileUpdate')
            ->call('confirmProfileUpdate')
            ->set('password', 'password')
            ->call('verifyPasswordAndSendOtp')
            ->set('otp', '000000')
            ->call('verifyOtpAndUpdateProfile')
            ->assertHasErrors(['otp']);

        $this->assertSame('Example User', $user->refresh()->name);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
