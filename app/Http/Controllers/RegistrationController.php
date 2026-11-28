<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\RosterImportState;
use App\Services\PendingRegistrationService;
use App\Services\TeacherAccountRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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

        if (! RosterImportState::isImported(RosterImportState::STUDENTS)) {
            return back()
                ->withInput()
                ->withErrors([
                    'id_number' => 'The guidance counselor has not imported the student list yet. Registration will be available after the student list is imported.',
                ]);
        }

        if (! $createNewUser->matchesStudentReference($validated)) {
            if (! $request->boolean('teacher_request_confirmation')) {
                return back()
                    ->withInput()
                    ->with('show_teacher_verification_modal', true);
            }

            $photoValidation = Validator::make($request->all(), [
                'verification_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            if ($photoValidation->fails()) {
                return back()
                    ->withInput()
                    ->with('show_teacher_verification_modal', true)
                    ->withErrors($photoValidation);
            }

            $teacherAccountRequests->createFromRegistration($validated, $request->file('verification_photo'));

            return to_route('home', ['panel' => 'register'])
                ->with('teacher_account_request_submitted', true)
                ->with('status', "Your request to open an account has been sent to the counselor. Please verify your email if prompted, then wait for the result. The counselor's decision will be sent to this email address.");
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
