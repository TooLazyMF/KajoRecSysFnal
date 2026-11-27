<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Services\EmailOtpService;
use App\Services\PendingRegistrationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailVerificationOtpController extends Controller
{
    public function registrationNotice(Request $request, PendingRegistrationService $pendingRegistration): RedirectResponse
    {
        if (! $pendingRegistration->registration($request)) {
            return to_route('register');
        }

        return to_route('home');
    }

    public function cancelRegistration(Request $request, PendingRegistrationService $pendingRegistration): RedirectResponse
    {
        if (! $pendingRegistration->registration($request)) {
            $pendingRegistration->finish($request);

            return to_route('home')
                ->with('registration_panel', true)
                ->withErrors(['registration' => 'There is no pending registration to cancel.']);
        }

        $registrationInput = $pendingRegistration->formInput($request);
        $pendingRegistration->finish($request);

        return to_route('home')
            ->withInput($registrationInput)
            ->with('registration_cancelled', true)
            ->with('status', 'Email verification cancelled. Your details are still here; submit the form when you are ready to try again.');
    }

    public function send(Request $request, PendingRegistrationService $pendingRegistration): RedirectResponse
    {
        try {
            if ($pendingRegistration->registration($request)) {
                $pendingRegistration->sendCode($request);

                return back()->with('status', 'A verification code has been sent to your email address.');
            }

            $user = $this->verificationUser($request);

            if (! $user instanceof User) {
                return to_route('home');
            }

            if ($user->hasVerifiedEmail()) {
                return to_route($this->dashboardRouteFor($user));
            }

            $user->sendEmailVerificationNotification();

            return back()->with('status', 'A verification code has been sent to your registered email address.');
        } catch (TransportExceptionInterface $exception) {
            Log::error('Email verification code could not be sent.', [
                'user_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            return back()
                ->with('mail_transport_error', true)
                ->withErrors([
                    'email' => 'Email delivery is unavailable. Please try again later or contact the administrator.',
                ]);
        }
    }

    public function verify(
        Request $request,
        EmailOtpService $otp,
        PendingRegistrationService $pendingRegistration,
        CreateNewUser $createNewUser,
    ): RedirectResponse {
        $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $registration = $pendingRegistration->registration($request);

        if ($registration) {
            if (! $pendingRegistration->verify($request, $request->string('otp')->toString())) {
                return back()
                    ->withErrors(['otp' => 'The code is invalid or has expired. Request a new code and try again.'])
                    ->withInput();
            }

            try {
                $user = $createNewUser->createVerified($registration);
            } catch (ValidationException $exception) {
                $registrationInput = $pendingRegistration->formInput($request);
                $pendingRegistration->finish($request);

                return to_route('home')
                    ->withInput($registrationInput)
                    ->with('registration_panel', true)
                    ->withErrors($exception->errors());
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            $pendingRegistration->finish($request);
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect()->to(route($this->dashboardRouteFor($user), absolute: false).'?verified=1');
        }

        $user = $this->verificationUser($request);

        if (! $user instanceof User) {
            return to_route('home');
        }

        if ($user->hasVerifiedEmail()) {
            return to_route($this->dashboardRouteFor($user));
        }

        if (! $otp->verify($user, 'email-verification', $request->string('otp')->toString())) {
            return back()
                ->withErrors(['otp' => 'The code is invalid or has expired. Request a new code and try again.'])
                ->with('email_otp_sent', true);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->to(route($this->dashboardRouteFor($user), absolute: false).'?verified=1');
    }

    private function verificationUser(Request $request): ?User
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser instanceof User) {
            return $authenticatedUser;
        }

        return null;
    }

    private function dashboardRouteFor(User $user): string
    {
        return match ($user->role) {
            'guidance_counselor' => 'guidance.dashboard',
            'student' => 'student.dashboard',
            'teacher' => 'teacher.dashboard',
            default => 'dashboard',
        };
    }
}
