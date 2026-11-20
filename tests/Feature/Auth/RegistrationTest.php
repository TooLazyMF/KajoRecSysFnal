<?php

namespace Tests\Feature\Auth;

use App\Models\StudentReference;
use App\Models\User;
use App\Notifications\EmailOtp;
use App\Services\PendingRegistrationService;
use App\Support\SensitiveLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('Student ID')
            ->assertSee('Gender')
            ->assertSee('Use an email address you can access. We will send a verification code; verify it to use your account.')
            ->assertSee('The guidance counselor has not imported the student list yet.');
    }

    public function test_welcome_page_displays_embedded_login_and_registration_panels(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-mode="welcome"', false)
            ->assertSee(route('login.root'), false)
            ->assertSee(route('register.store'), false)
            ->assertSee('data-auth-view="login"', false)
            ->assertSee('data-contact-content', false)
            ->assertSee('data-about-content', false)
            ->assertSee('data-welcome-content', false)
            ->assertSee('data-show-panel="contact"', false)
            ->assertSee('data-show-panel="about"', false)
            ->assertSee('data-show-panel="login"', false)
            ->assertSee('data-show-panel="register"', false)
            ->assertSee('Contact Us')
            ->assertSee('About Us')
            ->assertSee('What is KajoRec?')
            ->assertSee('Our Purpose')
            ->assertSee('Our Goals')
            ->assertSee('Who Can Use KajoRec?')
            ->assertSee('Students and Teachers')
            ->assertSee('mailto:kajorec@gmail.com')
            ->assertSee('name="_welcome_panel" value="register"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('Use an email address you can access. We will send a verification code; verify it to use your account.')
            ->assertSee('aria-controls="login-password"', false)
            ->assertSee('aria-controls="register-password"', false)
            ->assertSee('aria-controls="register-password-confirmation"', false)
            ->assertSee('data-loading-spinner', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('The guidance counselor has not imported the student list yet.')
            ->assertSee('The teacher list has not been imported yet.')
            ->assertSee('--primary: #09ba44;', false)
            ->assertSee('@media (prefers-color-scheme: dark)', false)
            ->assertSee('--primary: #20783e;', false)
            ->assertSee('name="theme-color" content="#09ba44"', false)
            ->assertSee('name="theme-color" content="#20783e"', false)
            ->assertSee('class="sticky top-0 z-50 border-b border-gray-200 bg-white"', false)
            ->assertSee('body.welcome-shell > header', false)
            ->assertSee('width: min(1180px, 100%);', false)
            ->assertSee('.page[data-mode="contact"] .form-panel,', false)
            ->assertSee('display: none;', false)
            ->assertSee('.page[data-mode="contact"] .welcome-panel,', false)
            ->assertSee('.page[data-mode="about"] .about-content {', false)
            ->assertSee('.page[data-mode="about"] .form-panel', false)
            ->assertSee('.page[data-mode="welcome"] .form-panel', false)
            ->assertSee('.page[data-mode="login"] .welcome-panel,', false)
            ->assertSee('showWelcomeView()', false);
    }

    public function test_registration_errors_return_to_the_registration_panel_on_the_welcome_page(): void
    {
        $response = $this->from(route('home'))->post(route('register.store'), [
            '_welcome_panel' => 'register',
            'first_name' => '',
            'last_name' => '',
            'id_number' => '',
            'gender' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertRedirect(route('home'))
            ->assertSessionHasErrors(['first_name', 'last_name', 'id_number', 'gender', 'email', 'password']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-mode="register"', false);
    }

    public function test_new_users_can_register(): void
    {
        Notification::fake();
        $this->createStudentReference('STUDENT-1001', 'John Doe', 'male');

        $response = $this->post(route('register.store'), [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'id_number' => 'STUDENT-1001',
            'gender' => 'male',
            'email' => 'test@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('home', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'test@example.com')]);
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="registration-verification-modal"', false)
            ->assertSee('m-auto max-h-[calc(100dvh-2rem)]', false)
            ->assertSee('Verify your email')
            ->assertSee('Your registration is pending. Verify your email address to activate your account.')
            ->assertSee('name="otp"', false)
            ->assertSee('Cancel');
        Notification::assertSentOnDemand(EmailOtp::class);
    }

    public function test_production_registration_rejects_a_log_mailer(): void
    {
        $originalEnvironment = $this->app['env'];
        $originalMailer = config('mail.default');
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);

        try {
            $request = Request::create('/');
            $request->setLaravelSession(app('session')->driver());

            app(PendingRegistrationService::class)->begin($request, [
                'email' => 'log-mailer@example.com',
                'password' => 'password',
            ]);
            $this->fail('A production log mailer must not be accepted for registration OTP delivery.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('real mail transport', $exception->getMessage());
        } finally {
            $this->app['env'] = $originalEnvironment;
            config(['mail.default' => $originalMailer]);
        }
    }

    public function test_new_users_are_authenticated_only_after_email_verification(): void
    {
        Notification::fake();
        $this->createStudentReference('STUDENT-1002', 'Jane Doe', 'female');

        $this->post(route('register.store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'id_number' => 'STUDENT-1002',
            'gender' => 'female',
            'email' => 'jane@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ])->assertRedirect(route('home', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'jane@example.com')]);
        $code = $this->registrationOtpCode('jane@example.com');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="registration-verification-modal"', false);
        $this->from(route('home'))
            ->post(route('verification.otp.verify'), ['otp' => $code])
            ->assertRedirect(route('student.dashboard', absolute: false).'?verified=1');

        $user = User::where('email_lookup_hash', SensitiveLookup::hash('email', 'jane@example.com'))->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_registration_code_keeps_the_verification_modal_open(): void
    {
        Notification::fake();
        $this->createStudentReference('STUDENT-OTP', 'Verify Later', 'male');

        $this->post(route('register.store'), [
            'first_name' => 'Verify',
            'last_name' => 'Later',
            'id_number' => 'STUDENT-OTP',
            'gender' => 'male',
            'email' => 'verify-later@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ])->assertRedirect(route('home', absolute: false));

        $code = $this->registrationOtpCode('verify-later@example.com');
        $invalidCode = $code === '999999' ? '000000' : '999999';

        $this->from(route('home'))
            ->post(route('verification.otp.verify'), ['otp' => $invalidCode])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('otp');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('id="registration-verification-modal"', false);
        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'verify-later@example.com')]);
    }

    public function test_registration_after_logout_does_not_restore_the_previous_account(): void
    {
        Notification::fake();
        $this->createStudentReference('STUDENT-1003', 'New Student', 'male');

        $previousUser = User::factory()->create();
        $this->actingAs($previousUser)->post(route('logout'));
        $this->assertGuest();

        $this->post(route('register.store'), [
            'first_name' => 'New',
            'last_name' => 'Student',
            'id_number' => 'STUDENT-1003',
            'gender' => 'male',
            'email' => 'new@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ])->assertRedirect(route('home', absolute: false));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'new@example.com')]);
    }

    public function test_canceling_email_verification_deletes_the_pending_registration(): void
    {
        Notification::fake();
        $this->createStudentReference('STUDENT-CANCEL', 'Cancel Registration', 'female');

        $this->post(route('register.store'), [
            'first_name' => 'Cancel',
            'last_name' => 'Registration',
            'id_number' => 'STUDENT-CANCEL',
            'gender' => 'female',
            'email' => 'cancel@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ])->assertRedirect(route('home', absolute: false));

        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'cancel@example.com')]);

        $this->post(route('registration.cancel'))
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHas('status', 'Email verification cancelled. Your details are still here; submit the form when you are ready to try again.');

        $this->assertDatabaseMissing('users', ['email_lookup_hash' => SensitiveLookup::hash('email', 'cancel@example.com')]);
        $this->assertGuest();
        $cancelledPage = $this->get(route('home'))
            ->assertOk()
            ->assertSee('Email verification cancelled.')
            ->assertDontSee('id="registration-verification-modal"', false);
        $cancelledPage->assertSee('name="first_name" type="text" value="Cancel"', false);
        $cancelledPage->assertSee('value="Registration"', false)
            ->assertSee('value="STUDENT-CANCEL"', false)
            ->assertSee('value="cancel@example.com"', false);
        $cancelledPage->assertDontSee('value="password"', false);

        $verifiedUser = User::factory()->create(['email' => 'verified@example.com']);
        $this->post(route('registration.cancel'))
            ->assertRedirect(route('home', absolute: false))
            ->assertSessionHasErrors('registration');

        $this->assertDatabaseHas('users', ['id' => $verifiedUser->id]);
    }

    public function test_registration_is_blocked_until_the_student_list_is_imported(): void
    {
        $this->from(route('home'))->post(route('register.store'), [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'id_number' => 'STUDENT-1004',
            'gender' => 'female',
            'email' => 'jane.no-roster@example.com',
            'password' => 'RegistrationPass123',
            'password_confirmation' => 'RegistrationPass123',
        ])->assertRedirect(route('home'))
            ->assertSessionHasErrors([
                'id_number' => 'The guidance counselor has not imported the student list yet. Registration will be available after the student list is imported.',
            ]);

        $this->assertDatabaseMissing('users', [
            'email_lookup_hash' => SensitiveLookup::hash('email', 'jane.no-roster@example.com'),
        ]);
    }

    private function createStudentReference(string $idNumber, string $name, string $gender): void
    {
        StudentReference::create([
            'id_number' => $idNumber,
            'name' => $name,
            'gender' => $gender,
        ]);
    }

    private function registrationOtpCode(string $email): string
    {
        $notifiable = (new AnonymousNotifiable)->route('mail', $email);
        $notification = Notification::sent($notifiable, EmailOtp::class)->first();

        $this->assertInstanceOf(EmailOtp::class, $notification);
        preg_match('/\b\d{6}\b/', $notification->toMail($notifiable)->introLines[1], $matches);
        $this->assertArrayHasKey(0, $matches);

        return $matches[0];
    }
}
