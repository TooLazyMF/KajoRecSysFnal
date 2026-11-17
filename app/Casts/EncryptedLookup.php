<?php

namespace App\Casts;

use App\Support\SensitiveLookup;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * @implements CastsAttributes<string|null, string|null>
 */
class EncryptedLookup implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null, $key.'_lookup_hash' => null];
        }

        $plainText = (string) $value;

        return [
            $key => Crypt::encryptString($plainText),
            $key.'_lookup_hash' => SensitiveLookup::hash($key, $plainText),
        ];
    }
}
