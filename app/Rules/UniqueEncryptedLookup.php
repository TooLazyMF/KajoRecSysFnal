<?php

namespace App\Rules;

use App\Support\SensitiveLookup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueEncryptedLookup implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $field,
        private readonly ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $exists = DB::table($this->table)
            ->where($this->field.'_lookup_hash', SensitiveLookup::hash($this->field, $value))
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail("The {$attribute} has already been taken.");
        }
    }
}
