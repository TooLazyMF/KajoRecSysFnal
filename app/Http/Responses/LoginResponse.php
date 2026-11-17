<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\AccountSessionLogger;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AccountSessionLogger $sessionLogger) {}

    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User) {
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

        return match ($user->role) {
            'guidance_counselor' => redirect()->route('guidance.dashboard'),
            'student' => redirect()->route('student.dashboard'),
            'teacher' => redirect()->route('teacher.dashboard'),
            default => redirect()->route('home'),
        };
    }
}
