<?php

namespace App\Http\Controllers;

use App\Models\AccountSessionLog;
use App\Models\User;
use App\Services\AccountSessionLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class AccountLogController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $validated['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $logs = AccountSessionLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('logged_in_at', [$start, $end])
            ->latest('logged_in_at')
            ->paginate(50)
            ->withQueryString();
        $currentLogId = $request->session()->get('account_session_log_id');
        $logoutableLogIds = collect();

        $pageLogs = $logs->getCollection();
        $logoutableLogIds = $pageLogs
            ->filter(fn (AccountSessionLog $log): bool => $log->logged_out_at === null
                && (int) $currentLogId === $log->id)
            ->pluck('id');

        if (config('session.driver') === 'database') {
            $sessionHashes = $pageLogs
                ->whereNull('logged_out_at')
                ->pluck('session_hash');

            $liveHashes = DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->id)
                ->whereIn('id', $sessionHashes)
                ->pluck('id');

            $logoutableLogIds = $logoutableLogIds->merge(
                $pageLogs
                    ->whereNull('logged_out_at')
                    ->whereIn('session_hash', $liveHashes)
                    ->pluck('id')
            )->unique()->values();
        }

        return view('pages.settings.account-logs', [
            'month' => $month,
            'logs' => $logs,
            'logoutableLogIds' => $logoutableLogIds,
        ]);
    }

    public function logoutDevice(
        Request $request,
        AccountSessionLog $accountSessionLog,
        AccountSessionLogger $sessionLogger
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $log = AccountSessionLog::query()
            ->where('user_id', $user->id)
            ->whereKey($accountSessionLog->getKey())
            ->firstOrFail();
        $isCurrentDevice = (int) $request->session()->get('account_session_log_id') === $log->id;

        if (! $sessionLogger->logoutDevice($request, $user, $log)) {
            return back()->withErrors([
                'device' => 'This session is no longer available to log out. Refresh the account logs and try again.',
            ]);
        }

        if ($isCurrentDevice) {
            Auth::guard('web')->logout();
            Session::invalidate();
            Session::regenerateToken();

            return redirect('/')->with('status', 'This device was logged out.');
        }

        return back()->with('status', 'The selected device was logged out.');
    }
}
