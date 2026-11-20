<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class GuidanceCounselorSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('role', 'guidance_counselor')->exists()) {
            return;
        }

        User::forceCreate([
            'name' => 'Administrator',
            'email' => null,
            'id_number' => null,
            'gender' => null,
            'role' => 'guidance_counselor',
            'password' => 'Admin',
            'must_change_password' => true,
        ]);
    }
}
