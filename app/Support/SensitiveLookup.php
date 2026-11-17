<?php

namespace App\Support;

use App\Models\StudentReference;

class SensitiveLookup
{
    public static function hash(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = match ($field) {
            'email' => mb_strtolower(trim($value)),
            'id_number' => mb_strtolower(StudentReference::normalizeIdNumber($value)),
            'name' => mb_strtolower(trim($value)),
            'session_id' => $value,
            default => throw new \InvalidArgumentException("Unsupported sensitive lookup field: {$field}"),
        };

        return hash_hmac('sha256', $field."\0".$normalized, (string) config('app.key'));
    }
}
