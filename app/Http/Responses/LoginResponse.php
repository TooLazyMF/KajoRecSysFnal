<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\AccountSessionLogger;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AccountSessionLogger $sessionLogger) {}

    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User) {
            if (! $this->sessionLogger->hasPendingLoginLocation($request)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'share_location' => 'Location access is required to sign in and record this device.',
                ]);
            }

            $this->sessionLogger->start($request, $user);
        }

        if ($user instanceof User
            && $user->role === 'teacher'
            && (
                ! filled($user->email)
                || ! filled($user->id_number)
                || $user->must_change_password
            )) {
            return redirect()->route('teacher.profile.edit');
        }

        if ($user instanceof User
            && $user->role === 'guidance_counselor'
            && (
                ! filled($user->email)
                || $user->name === 'Administrator'
                || $user->must_change_password
            )) {
            return redirect()->route('counselor.profile.edit');
        }

        return match ($user->role) {
            'guidance_counselor' => redirect()->route('guidance.dashboard'),
            'student' => redirect()->route('student.dashboard'),
            'teacher' => redirect()->route('teacher.dashboard'),
            default => redirect()->route('home'),
        };
    }
}
