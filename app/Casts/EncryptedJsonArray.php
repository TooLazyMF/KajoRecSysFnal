<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<array<int|string, mixed>|null, array<int|string, mixed>|null>
 */
class EncryptedJsonArray implements CastsAttributes
{
    /**
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $stored = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_string($stored)) {
            throw new InvalidArgumentException("The encrypted {$key} value must be a JSON string.");
        }

        $decoded = json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("The encrypted {$key} value must contain a JSON array.");
        }

        return $decoded;
    }

    /**
     * @throws JsonException
     *
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} value must be an array.");
        }

        $encrypted = Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));

        return [$key => json_encode($encrypted, JSON_THROW_ON_ERROR)];
    }
}
