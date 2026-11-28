<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Services\EmailOtpService;
use App\Support\SensitiveLookup;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new #[Title('Profile settings')] class extends Component
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $otp = '';

    public bool $showConfirmationModal = false;

    public bool $showPasswordModal = false;

    public bool $showOtpModal = false;

    #[Locked]
    public string $pendingName = '';

    #[Locked]
    public string $pendingEmail = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function beginProfileUpdate(): void
    {
        $this->resetErrorBag();
        $this->showConfirmationModal = true;
    }

    public function confirmProfileUpdate(): void
    {
        $user = Auth::user();
        $validated = $this->validate($this->profileRules($user->id));

        $currentEmailLookupHash = $user->getAttribute('email_lookup_hash');
        $newEmailLookupHash = SensitiveLookup::hash('email', $validated['email']);
        $emailChanged = ! is_string($currentEmailLookupHash)
            || ! hash_equals($currentEmailLookupHash, (string) $newEmailLookupHash);

        if ($user->name === $validated['name'] && ! $emailChanged) {
            $this->showConfirmationModal = false;
            Flux::toast(text: __('There are no profile changes to save.'));

            return;
        }

        $this->pendingName = $validated['name'];
        $this->pendingEmail = $validated['email'];
        $this->showConfirmationModal = false;
        $this->showPasswordModal = true;
    }

    public function verifyPasswordAndSendOtp(EmailOtpService $otp): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        $user = Auth::user();
        $rateLimitKey = 'profile-update-otp-send:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $this->addError('password', __('Please wait :seconds seconds before requesting another code.', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));

            return;
        }

        $pendingProfile = $this->validatePendingProfile($user->id);
        $currentEmailLookupHash = $user->getAttribute('email_lookup_hash');
        $newEmailLookupHash = SensitiveLookup::hash('email', $pendingProfile['email']);
        $emailChanged = ! is_string($currentEmailLookupHash)
            || ! hash_equals($currentEmailLookupHash, (string) $newEmailLookupHash);
        $recipientEmail = $emailChanged ? $pendingProfile['email'] : $user->email;

        try {
            $otp->send(
                $user,
                'profile-update',
                'Confirm your profile update',
                'Enter this code to confirm your profile name or email change:',
                $recipientEmail,
            );
        } catch (TransportExceptionInterface $exception) {
            Log::error('Profile update verification code could not be sent.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);

            $this->addError('password', 'Email delivery is unavailable. Please try again later or contact the administrator.');

            return;
        }

        RateLimiter::hit($rateLimitKey, 60);
        $this->showPasswordModal = false;
        $this->showOtpModal = true;
        $this->reset('password');
    }

    public function verifyOtpAndUpdateProfile(EmailOtpService $otp): void
    {
        $validated = $this->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = Auth::user();
        $rateLimitKey = 'profile-update-otp-attempt:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError('otp', __('Too many incorrect codes. Request a new code in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($rateLimitKey),
            ]));

            return;
        }

        if (! $otp->verify($user, 'profile-update', $validated['otp'])) {
            RateLimiter::hit($rateLimitKey, EmailOtpService::EXPIRATION_MINUTES * 60);
            $this->addError('otp', 'The code is invalid or has expired. Request a new code and try again.');

            return;
        }

        RateLimiter::clear($rateLimitKey);
        $pendingProfile = $this->validatePendingProfile($user->id);
        $currentEmailLookupHash = $user->getAttribute('email_lookup_hash');
        $newEmailLookupHash = SensitiveLookup::hash('email', $pendingProfile['email']);
        $emailChanged = ! is_string($currentEmailLookupHash)
            || ! hash_equals($currentEmailLookupHash, (string) $newEmailLookupHash);

        $user->name = $pendingProfile['name'];

        if ($emailChanged) {
            $user->email = $pendingProfile['email'];
            $user->email_verified_at = now();
        }

        $user->save();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->cancelProfileUpdate();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    public function handleProfileModalClose(): void
    {
        if (! $this->showConfirmationModal && ! $this->showPasswordModal && ! $this->showOtpModal) {
            $this->cancelProfileUpdate();
        }
    }

    private function validatePendingProfile(int $userId): array
    {
        return Validator::make([
            'name' => $this->pendingName,
            'email' => $this->pendingEmail,
        ], $this->profileRules($userId))->validate();
    }

    public function cancelProfileUpdate(): void
    {
        $this->showConfirmationModal = false;
        $this->showPasswordModal = false;
        $this->showOtpModal = false;
        $this->reset('password', 'otp', 'pendingName', 'pendingEmail');
        $this->resetErrorBag();
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="beginProfileUpdate" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification code.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification code has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if (auth()->user()->role === 'student')
            <div class="my-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/30">
                <flux:heading size="lg">Client Information Form</flux:heading>
                <flux:text class="mt-1">Update the personal, family, and educational information used by the Guidance Office.</flux:text>
                <a href="{{ route('profile.client-information.edit') }}" class="mt-4 inline-flex items-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    Edit CIF
                </a>
            </div>
        @endif

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>

    <flux:modal name="confirm-profile-update" wire:model="showConfirmationModal" @close="handleProfileModalClose" class="max-w-md">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Confirm profile change') }}</flux:heading>
                <flux:text>{{ __('Are you sure you want to change your user name or email?') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelProfileUpdate">{{ __('No') }}</flux:button>
                <flux:button variant="primary" wire:click="confirmProfileUpdate">{{ __('Yes') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="confirm-profile-password" wire:model="showPasswordModal" @close="handleProfileModalClose" class="max-w-md">
        <form wire:submit="verifyPasswordAndSendOtp" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Confirm your password') }}</flux:heading>
                <flux:text>{{ __('Enter your current password to continue with this profile change.') }}</flux:text>
            </div>

            <flux:input wire:model="password" :label="__('Password')" type="password" autocomplete="current-password" required viewable />
            @error('password')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelProfileUpdate">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Continue') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="confirm-profile-otp" wire:model="showOtpModal" @close="handleProfileModalClose" class="max-w-md">
        <form wire:submit="verifyOtpAndUpdateProfile" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Please enter the OTP sent to your email') }}</flux:heading>
                <flux:text>{{ __('Enter the 6-digit code sent to :email to confirm your profile change.', ['email' => $pendingEmail]) }}</flux:text>
            </div>

            <flux:otp wire:model="otp" length="6" label="OTP Code" />
            @error('otp')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="cancelProfileUpdate">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Verify and save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
