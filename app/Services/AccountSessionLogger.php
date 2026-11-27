<?php

namespace App\Services;

use App\Models\AccountSessionLog;
use App\Models\User;
use App\Support\SensitiveLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountSessionLogger
{
    public const IDLE_TIMEOUT_MINUTES = 10;

    private const LOG_ID_SESSION_KEY = 'account_session_log_id';

    private const LAST_ACTIVITY_SESSION_KEY = 'account_last_activity_at';

    private const LAST_DATABASE_TOUCH_SESSION_KEY = 'account_last_database_touch_at';

    public function start(Request $request, User $user): AccountSessionLog
    {
        $sessionHash = SensitiveLookup::hash('session_id', $request->session()->getId())
            ?? throw new \LogicException('A session ID hash could not be generated.');
        $now = CarbonImmutable::now();

        $log = AccountSessionLog::query()->firstOrCreate(
            ['session_hash' => $sessionHash],
            [
                'user_id' => $user->id,
                'device_name' => $this->deviceName($request->userAgent()),
                'logged_in_at' => $now,
                'last_activity_at' => $now,
            ],
        );

        $request->session()->put(self::LOG_ID_SESSION_KEY, $log->id);
        $request->session()->put(self::LAST_ACTIVITY_SESSION_KEY, $now->timestamp);
        $request->session()->put(self::LAST_DATABASE_TOUCH_SESSION_KEY, $now->timestamp);

        return $log;
    }

    public function logoutDevice(Request $request, User $user, AccountSessionLog $log): bool
    {
        $log = AccountSessionLog::query()
            ->where('user_id', $user->id)
            ->whereKey($log->getKey())
            ->firstOrFail();

        if ($log->logged_out_at !== null) {
            return false;
        }

        if ((int) $request->session()->get(self::LOG_ID_SESSION_KEY) === $log->id) {
            $this->close($request, $user);

            return true;
        }

        if (config('session.driver') !== 'database') {
            return false;
        }

        $sessionConnection = DB::connection(config('session.connection'));
        $accountConnection = $log->getConnection();
        $now = CarbonImmutable::now();
        $revokeAndUpdate = function () use ($sessionConnection, $accountConnection, $log, $user, $now): bool {
            $deleted = $sessionConnection->table(config('session.table'))
                ->where('id', $log->session_hash)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted === 0) {
                return false;
            }

            $updated = $accountConnection->table('account_session_logs')
                ->where('id', $log->id)
                ->where('user_id', $user->id)
                ->whereNull('logged_out_at')
                ->update([
                    'logged_out_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new \RuntimeException('The device session was revoked, but its account log could not be updated.');
            }

            return true;
        };

        if ($sessionConnection->getName() === $accountConnection->getName()) {
            return $sessionConnection->transaction($revokeAndUpdate);
        }

        return $revokeAndUpdate();
    }

    public function touch(Request $request): void
    {
        $logId = $request->session()->get(self::LOG_ID_SESSION_KEY);
        if (! is_numeric($logId)) {
            return;
        }

        $now = CarbonImmutable::now();
        $lastDatabaseTouch = $request->session()->get(self::LAST_DATABASE_TOUCH_SESSION_KEY, 0);
        // Track request-level idle time in the session, while limiting durable activity writes to once per minute.
        if ($now->timestamp - (int) $lastDatabaseTouch < 60) {
            return;
        }

        AccountSessionLog::query()
            ->whereKey((int) $logId)
            ->whereNull('logged_out_at')
            ->update(['last_activity_at' => $now]);

        $request->session()->put(self::LAST_DATABASE_TOUCH_SESSION_KEY, $now->timestamp);
    }

    public function close(Request $request, ?User $user = null, ?CarbonImmutable $loggedOutAt = null): void
    {
        $logId = $request->session()->get(self::LOG_ID_SESSION_KEY);
        if (! is_numeric($logId)) {
            return;
        }

        $query = AccountSessionLog::query()
            ->whereKey((int) $logId)
            ->whereNull('logged_out_at');

        if ($user !== null) {
            $query->where('user_id', $user->id);
        }

        $query->update(['logged_out_at' => $loggedOutAt ?? CarbonImmutable::now()]);
    }

    private function deviceName(?string $userAgent): string
    {
        $userAgent = $userAgent ?? '';
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Device',
        };

        return $browser.' on '.$platform;
    }
}
