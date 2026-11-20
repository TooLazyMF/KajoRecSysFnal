<?php

namespace App\Models;

use App\Casts\EncryptedLookup;
use App\Services\EmailOtpService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $id_number
 * @property string|null $gender
 * @property bool $is_active
 * @property bool $is_graduated
 * @property bool $must_change_password
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'id_number', 'gender', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'email_lookup_hash', 'id_number_lookup_hash', 'name_lookup_hash'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => EncryptedLookup::class,
            'email' => EncryptedLookup::class,
            'id_number' => EncryptedLookup::class,
            'gender' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'remember_token' => 'encrypted',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_graduated' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    public function sendEmailVerificationNotification(): void
    {
        app(EmailOtpService::class)->send(
            $this,
            'email-verification',
            'Verify your email address',
            'Enter this code to verify your email address:',
        );
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function slipAssignments(): HasMany
    {
        return $this->hasMany(SlipAssignment::class, 'student_id');
    }

    public function slipSubmissions(): HasMany
    {
        return $this->hasMany(SlipSubmission::class);
    }

    public function slipInformations(): HasMany
    {
        return $this->hasMany(SlipInformation::class);
    }

    public function accountSessionLogs(): HasMany
    {
        return $this->hasMany(AccountSessionLog::class);
    }
}
