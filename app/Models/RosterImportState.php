<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['roster_type', 'imported_at'])]
class RosterImportState extends Model
{
    public const STUDENTS = 'students';

    public const TEACHERS = 'teachers';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
        ];
    }

    public static function isImported(string $rosterType): bool
    {
        return static::query()->where('roster_type', $rosterType)->exists();
    }

    public static function markImported(string $rosterType): void
    {
        static::query()->updateOrCreate(
            ['roster_type' => $rosterType],
            ['imported_at' => now()],
        );
    }
}
