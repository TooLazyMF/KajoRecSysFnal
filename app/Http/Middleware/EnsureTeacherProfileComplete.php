<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeacherProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->role === 'teacher'
            && (
                ! filled($user->email)
                || ! filled($user->id_number)
                || $user->must_change_password
            )
            && ! $request->routeIs('teacher.profile.edit', 'teacher.profile.update', 'logout')) {
            return to_route('teacher.profile.edit');
        }

        if ($user instanceof User
            && $user->role === 'guidance_counselor'
            && (
                ! filled($user->email)
                || $user->name === 'Administrator'
                || $user->must_change_password
            )
            && ! $request->routeIs('counselor.profile.edit', 'counselor.profile.update', 'logout')) {
            return to_route('counselor.profile.edit');
        }

        return $next($request);
    }
}
