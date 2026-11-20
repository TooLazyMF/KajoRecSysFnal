<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Rules\UniqueEncryptedLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class TeacherProfileController extends Controller
{
    use PasswordValidationRules;

    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role === 'teacher', 403);

        if (filled($user->email) && filled($user->id_number) && ! $user->must_change_password) {
            return $user->hasVerifiedEmail()
                ? to_route('teacher.dashboard')
                : to_route('verification.notice');
        }

        return view('teacher.complete-profile', compact('user'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role === 'teacher', 403);

        if (filled($user->email) && filled($user->id_number) && ! $user->must_change_password) {
            return to_route('teacher.profile.edit');
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', new UniqueEncryptedLookup('users', 'email', $user->id)],
            'id_number' => ['required', 'string', 'max:255', new UniqueEncryptedLookup('users', 'id_number', $user->id)],
            'password' => $this->passwordRules(),
        ]);

        if (Hash::check($validated['password'], $user->getAuthPassword())) {
            return back()->withErrors([
                'password' => 'Choose a new password different from your initial password.',
            ]);
        }

        $emailChanged = $user->email !== $validated['email'];
        $user->forceFill([
            'email' => $validated['email'],
            'id_number' => $validated['id_number'],
            'password' => $validated['password'],
            'must_change_password' => false,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return to_route('verification.notice')
            ->with('status', 'Your details and new password were saved. A verification code was sent to your email address.');
    }
}
