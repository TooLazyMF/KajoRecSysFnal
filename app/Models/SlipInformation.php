<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'full_name',
    'last_name',
    'first_name',
    'middle_initial',
    'student_id',
    'email',
    'phone',
    'degree',
    'year_level',
    'school_year',
    'purpose',
    'purpose_other',
    'pdf_path',
    'review_status',
    'approved_by',
    'approved_at',
])]
class SlipInformation extends Model
{
    protected $table = 'slip_informations';

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'last_name' => 'encrypted',
            'first_name' => 'encrypted',
            'middle_initial' => 'encrypted',
            'student_id' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'degree' => 'encrypted',
            'year_level' => 'encrypted',
            'school_year' => 'encrypted',
            'purpose' => 'encrypted',
            'purpose_other' => 'encrypted',
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