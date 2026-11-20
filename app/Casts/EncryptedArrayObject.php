<?php

namespace App\Casts;

use ArrayObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

/**
 * @implements CastsAttributes<ArrayObject<string, mixed>|null, ArrayObject<string, mixed>|array<string, mixed>|null>
 */
class EncryptedArrayObject implements CastsAttributes
{
    /**
     * @throws JsonException
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("The encrypted {$key} value must contain a JSON object.");
        }

        return new ArrayObject($decoded);
    }

    /**
     * @return array<string, string|null>
     *
     * @throws JsonException
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

        return [$key => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR))];
    }
}
