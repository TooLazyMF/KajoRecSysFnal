<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Services\TeacherAccountRequestService;
use App\Services\PendingRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegistrationController extends Controller
{
    public function store(
        Request $request,
        CreateNewUser $createNewUser,
        PendingRegistrationService $pendingRegistration,
        TeacherAccountRequestService $teacherAccountRequests,
    ): RedirectResponse {
        $validated = $createNewUser->validateRegistration($request->all());

        if (! $createNewUser->matchesStudentReference($validated)) {
            $request->validate([
                'verification_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $teacherAccountRequests->createFromRegistration($validated, $request->file('verification_photo'));

            return to_route('home', ['panel' => 'register'])
                ->with('status', 'Your teacher account request was submitted. Verify your email from the link we sent so the guidance counselor can review it.');
        }

        try {
            $pendingRegistration->begin($request, $validated);
        } catch (TransportExceptionInterface $exception) {
            Log::error('Registration verification email could not be sent.', [
                'email' => $validated['email'] ?? null,
                'exception' => $exception,
            ]);

            return back()
                ->withInput($pendingRegistration->formInput($request))
                ->with('mail_transport_error', true)
                ->withErrors([
                    'email' => 'Email delivery is unavailable. Please try again later or contact the administrator.',
                ]);
        }

        return to_route('home')
            ->withInput($pendingRegistration->formInput($request))
            ->with('status', 'Your registration is pending. Verify your email address to activate your account.');
    }
}
