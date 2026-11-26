<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class GuidanceCounselorSeeder extends Seeder
{
    public function run(): void
    {
        $counselor = User::query()->where('role', 'guidance_counselor')->first();

        if ($counselor && ($counselor->name !== 'Administrator' || filled($counselor->email))) {
            return;
        }

        $email = config('guidance.initial_counselor.email');
        $password = config('guidance.initial_counselor.password');

        if (! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($password)
            || strlen($password) < 8) {
            throw new RuntimeException(
                'Set INITIAL_COUNSELOR_EMAIL and INITIAL_COUNSELOR_PASSWORD (at least 8 characters) before seeding the initial counselor.'
            );
        }

        $attributes = [
            'name' => 'Administrator',
            'email' => $email,
            'id_number' => null,
            'gender' => null,
            'role' => 'guidance_counselor',
            'password' => $password,
            'must_change_password' => true,
        ];

        if ($counselor) {
            $counselor->forceFill($attributes)->save();

            return;
        }

        User::forceCreate($attributes);
    }
}
