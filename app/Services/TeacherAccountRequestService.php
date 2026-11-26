<?php

namespace App\Services;

use App\Models\TeacherAccountRequest;
use App\Models\User;
use App\Notifications\TeacherAccountRequestVerification;
use App\Notifications\WorkflowActivity;
use App\Support\SensitiveLookup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherAccountRequestService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromRegistration(array $validated, UploadedFile $photo): TeacherAccountRequest
    {
        $name = implode(' ', array_filter([
            trim((string) $validated['first_name']),
            filled($validated['middle_initial'] ?? null) ? trim((string) $validated['middle_initial']).'.' : null,
            trim((string) $validated['last_name']),
            filled($validated['name_extension'] ?? null) ? trim((string) $validated['name_extension']) : null,
        ]));
        $idNumber = (string) $validated['id_number'];
        $photoContents = file_get_contents($photo->getRealPath());
        $photoMimeType = $photo->getMimeType();

        if (! is_string($photoContents) || ! is_string($photoMimeType)) {
            throw ValidationException::withMessages(['verification_photo' => 'The verification image could not be read.']);
        }

        $photoExtension = match ($photoMimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['verification_photo' => 'Upload a JPG, PNG, or WebP image.']),
        };
        $photoPath = 'teacher-account-requests/'.Str::uuid().'.'.$photoExtension;

        if (! Storage::disk('local')->put($photoPath, Crypt::encryptString($photoContents))) {
            throw ValidationException::withMessages(['verification_photo' => 'The verification image could not be saved.']);
        }

        try {
            $teacherRequest = DB::transaction(function () use ($validated, $name, $idNumber, $photoPath, $photoMimeType): TeacherAccountRequest {
                $emailLookupHash = SensitiveLookup::hash('email', (string) $validated['email']);
                $idNumberLookupHash = SensitiveLookup::hash('id_number', $idNumber);

                if (TeacherAccountRequest::query()->where('email_lookup_hash', $emailLookupHash)->where('status', '!=', TeacherAccountRequest::DECLINED)->exists()
                    || TeacherAccountRequest::query()->where('id_number_lookup_hash', $idNumberLookupHash)->where('status', '!=', TeacherAccountRequest::DECLINED)->exists()) {
                    throw ValidationException::withMessages(['email' => 'A teacher account request already exists for these credentials.']);
                }

                return TeacherAccountRequest::query()->create([
                    'name' => $name,
                    'email' => $validated['email'],
                    'id_number' => $idNumber,
                    'password' => $validated['password'],
                    'id_photo_path' => $photoPath,
                    'id_photo_mime_type' => $photoMimeType,
                    'status' => TeacherAccountRequest::PENDING,
                    'email_verified_at' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($photoPath);
            throw $exception;
        }

        Notification::route('mail', $teacherRequest->email)
            ->notify(new TeacherAccountRequestVerification($teacherRequest));

        foreach (User::query()->where('role', 'guidance_counselor')->get() as $counselor) {
            $counselor->notify(new WorkflowActivity(
                'Teacher account request',
                'Teacher '.$teacherRequest->name.' is requesting approval to open an account.',
                route('admin.users.index').'#teacher-account-request-'.$teacherRequest->id,
                'teacher-account-request',
                $teacherRequest->id,
            ));
        }

        return $teacherRequest;
    }
}
