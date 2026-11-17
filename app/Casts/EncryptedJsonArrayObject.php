<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;
use ArrayObject;

/**
 * @implements CastsAttributes<ArrayObject<string, mixed>|null, ArrayObject<string, mixed>|array<string, mixed>|null>
 */
class EncryptedJsonArrayObject implements CastsAttributes
{
    /**
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
    {
        if ($value === null) {
            return null;
        }

        $encrypted = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_string($encrypted)) {
            throw new InvalidArgumentException("The encrypted {$key} value must be a JSON string.");
        }

        $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("The encrypted {$key} value must contain a JSON object.");
        }

        return new ArrayObject($decoded);
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

        if ($value instanceof ArrayObject) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("The {$key} value must be an array or ArrayObject.");
        }

        $encrypted = Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));

        return [$key => json_encode($encrypted, JSON_THROW_ON_ERROR)];
    }
}
