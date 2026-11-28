<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailOtp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class EmailOtpService
{
    public const EXPIRATION_MINUTES = 30;

    public function send(
        User $user,
        string $purpose,
        string $subject,
        string $message,
        ?string $recipientEmail = null,
    ): void {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->cacheKey($user, $purpose), [
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(self::EXPIRATION_MINUTES));

        $notification = new EmailOtp($code, $subject, $message, $user->name);

        if ($recipientEmail !== null) {
            Notification::route('mail', $recipientEmail)->notify($notification);

            return;
        }

        $user->notify($notification);
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $key = $this->cacheKey($user, $purpose);
        $otp = Cache::get($key);

        // Only the hash is cached; a successful comparison consumes it so the code cannot be replayed.
        if (! is_array($otp)
            || ! isset($otp['code_hash'])
            || ! is_string($otp['code_hash'])
            || ! Hash::check($code, $otp['code_hash'])) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    private function cacheKey(User $user, string $purpose): string
    {
        return 'email-otp:'.Str::slug($purpose).':'.$user->getKey();
    }
}
