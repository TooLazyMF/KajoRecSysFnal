<?php

namespace App\Models;

use App\Casts\EncryptedLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $id_number
 * @property string|null $password
 * @property string|null $id_photo_path
 * @property string|null $id_photo_mime_type
 * @property string $status
 * @property Carbon|null $email_verified_at
 * @property string|null $decision_message
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int|null $user_id
 */
#[Fillable([
    'name',
    'email',
    'id_number',
    'password',
    'id_photo_path',
    'id_photo_mime_type',
    'status',
    'email_verified_at',
    'decision_message',
    'reviewed_by',
    'reviewed_at',
    'user_id',
])]
#[Hidden(['password', 'name_lookup_hash', 'email_lookup_hash', 'id_number_lookup_hash'])]
class TeacherAccountRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected function casts(): array
    {
        return [
            'name' => EncryptedLookup::class,
            'email' => EncryptedLookup::class,
            'id_number' => EncryptedLookup::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
