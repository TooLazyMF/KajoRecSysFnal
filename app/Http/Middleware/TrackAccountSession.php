<?php

namespace App\Http\Middleware;

use App\Models\AccountSessionLog;
use App\Models\User;
use App\Services\AccountSessionLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackAccountSession
{
    public function __construct(private readonly AccountSessionLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $logId = $request->session()->get('account_session_log_id');
            if (is_numeric($logId)
                && AccountSessionLog::query()
                    ->whereKey((int) $logId)
                    ->where('user_id', $user->id)
                    ->whereNotNull('logged_out_at')
                    ->exists()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return to_route('login')->with('status', 'This device was logged out from your account.');
            }

            $lastActivity = $request->session()->get('account_last_activity_at');
            if (is_numeric($lastActivity)
                && (int) $lastActivity <= now()->subMinutes(AccountSessionLogger::IDLE_TIMEOUT_MINUTES)->timestamp) {
                $loggedOutAt = CarbonImmutable::createFromTimestamp((int) $lastActivity, config('app.timezone'))
                    ->addMinutes(AccountSessionLogger::IDLE_TIMEOUT_MINUTES);
                $this->logger->close($request, $user, $loggedOutAt);

                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return to_route('login')->with('status', 'You were logged out after 10 minutes of inactivity.');
            }

            if (! $request->session()->has('account_session_log_id')) {
                $this->logger->start($request, $user);
            }

            $this->logger->touch($request);
            $request->session()->put('account_last_activity_at', now()->timestamp);
        }

        $response = $next($request);

        $user = $request->user();
        if ($user instanceof User && ! $request->session()->has('account_session_log_id')) {
            $this->logger->start($request, $user);
        }

        return $response;
    }
}
