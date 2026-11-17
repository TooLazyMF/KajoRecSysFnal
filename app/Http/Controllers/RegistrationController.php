<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
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
    ): RedirectResponse {
        $validated = $createNewUser->validate($request->all());
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
                    'email' => 'Please check your internet connection and try again. We could not send the verification email.',
                ]);
        }

        return to_route('home')
            ->withInput($pendingRegistration->formInput($request))
            ->with('status', 'Your registration is pending. Verify your email address to activate your account.');
    }
}
