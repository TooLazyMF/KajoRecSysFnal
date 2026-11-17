<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'name_lookup_hash'])]
class TeacherReference extends Model
{
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
        ];
    }

    public static function normalizeName(?string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim((string) $name))) ?? '';
    }
}
