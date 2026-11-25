<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\RosterImportState;
use App\Models\StudentReference;
use App\Models\User;
use App\Rules\UniqueEncryptedLookup;
use App\Support\SensitiveLookup;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        return $this->createVerified($this->validate($input));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $input['id_number'] = is_string($input['id_number'] ?? null)
            ? trim($input['id_number'])
            : '';

        $validated = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'name_extension' => ['nullable', 'string', 'max:20'],
            'email' => $this->emailRules(),
            'id_number' => ['required', 'string', 'max:255', 'regex:/\A[0-9]+\z/D', new UniqueEncryptedLookup('users', 'id_number')],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'same:password'],
        ])->validate();

        $this->validateStudentReference($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createVerified(array $validated): User
    {
        Validator::make($validated, [
            'email' => $this->emailRules(),
            'id_number' => ['required', 'string', 'max:255', 'regex:/\A[0-9]+\z/D', new UniqueEncryptedLookup('users', 'id_number')],
        ])->validate();

        $this->validateStudentReference($validated);

        return User::create([
            'name' => implode(' ', array_filter([
                trim($validated['first_name']),
                filled($validated['middle_initial'] ?? null) ? trim($validated['middle_initial']).'.' : null,
                trim($validated['last_name']),
                filled($validated['name_extension'] ?? null) ? trim($validated['name_extension']) : null,
            ])),
            'id_number' => StudentReference::normalizeIdNumber($validated['id_number']),
            'gender' => $validated['gender'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateStudentReference(array $validated): void
    {
        $normalizedIdNumber = StudentReference::normalizeIdNumber($validated['id_number'] ?? '');
        if (! RosterImportState::isImported(RosterImportState::STUDENTS)) {
            throw ValidationException::withMessages([
                'id_number' => 'The guidance counselor has not imported the student list yet. Registration will be available after the student list is imported.',
            ]);
        }

        $reference = StudentReference::query()
            ->where('id_number_lookup_hash', SensitiveLookup::hash('id_number', $normalizedIdNumber))
            ->first(['id_number', 'name', 'gender']);

        if (
            $reference === null
            || $this->normalizeName($reference->name) !== $this->normalizeName($this->registrationName($validated))
            || $reference->gender !== $validated['gender']
        ) {
            throw ValidationException::withMessages([
                'id_number' => 'Please enter credentials that match the student reference.',
            ]);
        }
    }

    /** @param array<string, string|null> $input */
    private function registrationName(array $input): string
    {
        return implode(' ', array_filter([
            trim($input['first_name']),
            filled($input['middle_initial'] ?? null) ? trim($input['middle_initial']).'.' : null,
            trim($input['last_name']),
            filled($input['name_extension'] ?? null) ? trim($input['name_extension']) : null,
        ]));
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name))) ?? '';
    }
}
