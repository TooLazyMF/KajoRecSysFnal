<?php

namespace App\Services;

use App\Notifications\EmailOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class PendingRegistrationService
{
    public const SESSION_KEY = 'pending_email_verification_registration';

    public const INPUT_SESSION_KEY = 'pending_registration_form_input';

    private const OTP_PURPOSE = 'registration-email-verification';

    /**
     * @param  array<string, mixed>  $registration
     */
    public function begin(Request $request, array $registration): void
    {
        $token = Str::random(64);
        unset($registration['password_confirmation']);
        $registration['password'] = Hash::make($registration['password']);

        Cache::put($this->cacheKey($token), $registration, now()->addMinutes(EmailOtpService::EXPIRATION_MINUTES));
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $token);
        $request->session()->put(self::INPUT_SESSION_KEY, $this->safeInput($registration));

        $this->sendCode($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function registration(Request $request): ?array
    {
        $token = $request->session()->get(self::SESSION_KEY);
        $registration = is_string($token) ? Cache::get($this->cacheKey($token)) : null;

        return is_array($registration) ? $registration : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function formInput(Request $request): array
    {
        $input = $request->session()->get(self::INPUT_SESSION_KEY, []);

        return is_array($input) ? $input : [];
    }

    public function sendCode(Request $request): void
    {
        $token = $request->session()->get(self::SESSION_KEY);

        if (! is_string($token)) {
            return;
        }

        $registration = Cache::get($this->cacheKey($token));

        if (! is_array($registration)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->otpCacheKey($token), Hash::make($code), now()->addMinutes(EmailOtpService::EXPIRATION_MINUTES));

        Notification::route('mail', $registration['email'])->notify(
            new EmailOtp(
                $code,
                'Verify your email address',
                'Enter this code to verify your email address and complete registration:',
                $this->registrationName($registration),
            ),
        );
    }

    public function verify(Request $request, string $code): bool
    {
        $token = $request->session()->get(self::SESSION_KEY);
        $hash = is_string($token) ? Cache::get($this->otpCacheKey($token)) : null;

        if (! is_string($hash) || ! Hash::check($code, $hash)) {
            return false;
        }

        Cache::forget($this->otpCacheKey($token));

        return true;
    }

    public function finish(Request $request): void
    {
        $token = $request->session()->pull(self::SESSION_KEY);

        if (is_string($token)) {
            Cache::forget($this->cacheKey($token));
            Cache::forget($this->otpCacheKey($token));
        }

        $request->session()->forget(self::INPUT_SESSION_KEY);
    }

    /**
     * @param  array<string, mixed>  $registration
     * @return array<string, mixed>
     */
    private function safeInput(array $registration): array
    {
        return array_intersect_key($registration, array_flip([
            'first_name',
            'last_name',
            'middle_initial',
            'name_extension',
            'id_number',
            'gender',
            'email',
        ]));
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    private function registrationName(array $registration): string
    {
        return implode(' ', array_filter([
            trim($registration['first_name']),
            filled($registration['middle_initial'] ?? null) ? trim($registration['middle_initial']).'.' : null,
            trim($registration['last_name']),
            filled($registration['name_extension'] ?? null) ? trim($registration['name_extension']) : null,
        ]));
    }

    private function cacheKey(string $token): string
    {
        return 'pending-registration:'.hash('sha256', $token);
    }

    private function otpCacheKey(string $token): string
    {
        return self::OTP_PURPOSE.':'.hash('sha256', $token);
    }
}
