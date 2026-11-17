<?php

namespace App\Models;

use App\Casts\EncryptedArrayObject;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'slip', 'data', 'pdf_path', 'review_status', 'approved_by', 'approved_at'])]
class SlipSubmission extends Model
{
    protected function casts(): array
    {
        return [
            'data' => EncryptedArrayObject::class,
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
