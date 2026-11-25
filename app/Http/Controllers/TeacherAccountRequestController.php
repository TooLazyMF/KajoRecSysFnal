<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\RosterImportState;
use App\Models\StudentReference;
use App\Models\TeacherAccountRequest;
use App\Models\TeacherReference;
use App\Models\User;
use App\Notifications\TeacherAccountRequestDecision;
use App\Notifications\TeacherAccountRequestVerification;
use App\Rules\UniqueEncryptedLookup;
use App\Support\SensitiveLookup;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class TeacherAccountRequestController extends Controller
{
    use PasswordValidationRules;

    public function create(): View
    {
        $teacherReferences = RosterImportState::isImported(RosterImportState::TEACHERS)
            ? TeacherReference::query()
                ->get(['id', 'name'])
                ->sortBy(fn (TeacherReference $teacher) => mb_strtolower($teacher->name))
                ->values()
            : collect();

        return view('pages::auth.teacher-register', compact('teacherReferences'));
    }

    public function store(Request $request): RedirectResponse
    {
        if (! RosterImportState::isImported(RosterImportState::TEACHERS)) {
            throw ValidationException::withMessages([
                'teacher_name' => 'The guidance counselor has not imported the teacher list yet.',
            ]);
        }

        $validated = $request->validate([
            'teacher_name' => ['required', 'string', 'max:255'],
            'teacher_id' => ['required', 'string', 'max:255', 'regex:/\A[0-9]+\z/D', new UniqueEncryptedLookup('users', 'id_number')],
            'email' => ['required', 'email', 'max:255', new UniqueEncryptedLookup('users', 'email')],
            'id_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $teacherReference = TeacherReference::query()
            ->where('name_lookup_hash', SensitiveLookup::hash(
                'name',
                TeacherReference::normalizeName($validated['teacher_name']),
            ))
            ->first(['name']);

        if (! $teacherReference instanceof TeacherReference) {
            return back()
                ->withErrors(['teacher_name' => 'Select a teacher name from the imported teacher list.'])
                ->withInput($request->except(['password', 'password_confirmation', 'id_photo']));
        }

        $emailLookupHash = SensitiveLookup::hash('email', $validated['email']);
        $teacherId = StudentReference::normalizeIdNumber($validated['teacher_id']);
        $idNumberLookupHash = SensitiveLookup::hash('id_number', $teacherId);
        $existingRequest = TeacherAccountRequest::query()
            ->where('email_lookup_hash', $emailLookupHash)
            ->first();

        if ($existingRequest && $existingRequest->status !== TeacherAccountRequest::DECLINED) {
            return back()
                ->withErrors(['email' => 'A teacher account request already exists for this email address.'])
                ->withInput($request->except(['password', 'password_confirmation', 'id_photo']));
        }

        if (TeacherAccountRequest::query()
            ->where('id_number_lookup_hash', $idNumberLookupHash)
            ->where('status', '!=', TeacherAccountRequest::DECLINED)
            ->when($existingRequest, fn ($query) => $query->where('id', '!=', $existingRequest->getKey()))
            ->exists()) {
            return back()
                ->withErrors(['teacher_id' => 'A teacher account request already exists for this teacher ID.'])
                ->withInput($request->except(['password', 'password_confirmation', 'id_photo']));
        }

        $uploadedPhoto = $request->file('id_photo');
        $photoContents = file_get_contents($uploadedPhoto->getRealPath());
        $photoMimeType = $uploadedPhoto->getMimeType();

        if (! is_string($photoContents) || ! is_string($photoMimeType)) {
            throw ValidationException::withMessages([
                'id_photo' => 'The ID photo could not be read. Please choose another photo.',
            ]);
        }

        $photoExtension = match ($photoMimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages([
                'id_photo' => 'Upload a JPG, PNG, or WebP image.',
            ]),
        };
        $photoPath = 'teacher-account-requests/'.Str::uuid().'.'.$photoExtension;

        if (! Storage::disk('local')->put($photoPath, Crypt::encryptString($photoContents))) {
            throw ValidationException::withMessages([
                'id_photo' => 'The ID photo could not be saved. Please try again.',
            ]);
        }

        try {
            $teacherRequest = DB::transaction(function () use ($validated, $teacherReference, $teacherId, $photoPath, $photoMimeType, $existingRequest): TeacherAccountRequest {
                $attributes = [
                    'name' => $teacherReference->name,
                    'email' => $validated['email'],
                    'id_number' => $teacherId,
                    'password' => $validated['password'],
                    'id_photo_path' => $photoPath,
                    'id_photo_mime_type' => $photoMimeType,
                    'status' => TeacherAccountRequest::PENDING,
                    'email_verified_at' => null,
                    'decision_message' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'user_id' => null,
                ];

                if ($existingRequest) {
                    $existingRequest->forceFill($attributes)->save();

                    return $existingRequest->refresh();
                }

                return TeacherAccountRequest::query()->create($attributes);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($photoPath);

            throw $exception;
        }

        try {
            $this->sendVerificationEmail($teacherRequest);
        } catch (TransportExceptionInterface $exception) {
            Log::error('Teacher account request verification email could not be sent.', [
                'teacher_account_request_id' => $teacherRequest->id,
                'exception' => $exception,
            ]);

            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'id_photo']))
                ->withErrors(['email' => 'Your request was saved, but we could not send the verification email. Use the resend form below or try again later.']);
        }

        return to_route('teacher.register')
            ->with('status', 'Your teacher account request was submitted. Check your email to verify it before counselor review.');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $teacherRequest = TeacherAccountRequest::query()
            ->where('email_lookup_hash', SensitiveLookup::hash('email', $validated['email']))
            ->where('status', TeacherAccountRequest::PENDING)
            ->whereNull('email_verified_at')
            ->first();

        if ($teacherRequest) {
            try {
                $this->sendVerificationEmail($teacherRequest);
            } catch (TransportExceptionInterface $exception) {
                Log::error('Teacher account request verification email could not be resent.', [
                    'teacher_account_request_id' => $teacherRequest->id,
                    'exception' => $exception,
                ]);

                return back()->withErrors(['email' => 'The verification email could not be sent. Please try again later.']);
            }
        }

        return back()->with('status', 'If an unverified teacher request exists for that email, a verification link has been sent.');
    }

    public function verify(Request $request, TeacherAccountRequest $teacherAccountRequest): RedirectResponse
    {
        if ($teacherAccountRequest->status === TeacherAccountRequest::DECLINED) {
            return to_route('teacher.register')
                ->with('status', 'This request was declined. You can submit a corrected request.');
        }

        if ($teacherAccountRequest->status !== TeacherAccountRequest::PENDING) {
            return to_route('teacher.register')
                ->with('status', 'This teacher account request has already been processed.');
        }

        if ($teacherAccountRequest->email_verified_at === null) {
            $teacherAccountRequest->forceFill(['email_verified_at' => now()])->save();
        }

        return to_route('teacher.register')
            ->with('status', 'Your email is verified. The guidance counselor can now review your request.');
    }

    public function showIdPhoto(TeacherAccountRequest $teacherAccountRequest)
    {
        abort_unless(
            $teacherAccountRequest->status === TeacherAccountRequest::PENDING
                && $teacherAccountRequest->email_verified_at !== null
                && is_string($teacherAccountRequest->id_photo_path)
                && Storage::disk('local')->exists($teacherAccountRequest->id_photo_path),
            404,
        );

        $encryptedPhoto = Storage::disk('local')->get($teacherAccountRequest->id_photo_path);
        if (! is_string($encryptedPhoto)) {
            Log::error('Teacher account request ID photo could not be read from private storage.', [
                'teacher_account_request_id' => $teacherAccountRequest->id,
            ]);

            abort(500, 'The ID photo could not be read.');
        }

        try {
            $photoContents = Crypt::decryptString($encryptedPhoto);
        } catch (DecryptException $exception) {
            Log::error('Teacher account request ID photo could not be decrypted.', [
                'teacher_account_request_id' => $teacherAccountRequest->id,
                'exception' => $exception,
            ]);

            abort(500, 'The ID photo could not be decrypted.');
        }

        return response($photoContents, 200, [
            'Content-Type' => $teacherAccountRequest->id_photo_mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function approve(Request $request, TeacherAccountRequest $teacherAccountRequest): RedirectResponse
    {
        [$teacherRequest, $user, $photoPath] = DB::transaction(function () use ($request, $teacherAccountRequest): array {
            $lockedRequest = TeacherAccountRequest::query()
                ->lockForUpdate()
                ->findOrFail($teacherAccountRequest->getKey());

            // Lock and recheck inside the transaction so concurrent reviews cannot both process this request.
            if ($lockedRequest->status !== TeacherAccountRequest::PENDING) {
                throw ValidationException::withMessages(['request' => 'This request has already been reviewed.']);
            }

            if ($lockedRequest->email_verified_at === null) {
                throw ValidationException::withMessages(['request' => 'The teacher must verify their email before counselor review.']);
            }

            if ($lockedRequest->password === null) {
                throw ValidationException::withMessages(['request' => 'The request has no password set. Ask the teacher to submit it again.']);
            }

            $photoPath = $lockedRequest->id_photo_path;
            $lockedRequest->forceFill([
                'status' => TeacherAccountRequest::APPROVED,
                'reviewed_by' => $request->user()->getKey(),
                'reviewed_at' => now(),
                'decision_message' => null,
            ])->save();

            $user = User::forceCreate([
                'name' => $lockedRequest->name,
                'email' => $lockedRequest->email,
                'id_number' => $lockedRequest->id_number,
                'gender' => null,
                'role' => 'teacher',
                'password' => $lockedRequest->password,
                'email_verified_at' => $lockedRequest->email_verified_at,
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $lockedRequest->forceFill([
                'user_id' => $user->getKey(),
                'password' => null,
            ])->save();

            return [$lockedRequest->refresh(), $user, $photoPath];
        });

        $photoDeleted = $this->deleteIdPhoto($teacherRequest, $photoPath);
        $emailSent = $this->sendDecisionEmail($teacherRequest, true);

        return to_route('admin.users.index')->with(
            'status',
            'Teacher account approved and activated.'
                .($emailSent ? ' An approval email was sent.' : ' The approval email could not be sent; check the mail configuration.')
                .($photoDeleted ? ' The ID photo was deleted.' : ' The ID photo could not be deleted; contact the system administrator.'),
        );
    }

    public function decline(Request $request, TeacherAccountRequest $teacherAccountRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decision_message' => ['nullable', 'string', 'max:1000'],
        ]);

        [$teacherRequest, $photoPath] = DB::transaction(function () use ($request, $teacherAccountRequest, $validated): array {
            $lockedRequest = TeacherAccountRequest::query()
                ->lockForUpdate()
                ->findOrFail($teacherAccountRequest->getKey());

            // The same locked state check protects the decline transition from racing with approval.
            if ($lockedRequest->status !== TeacherAccountRequest::PENDING) {
                throw ValidationException::withMessages(['request' => 'This request has already been reviewed.']);
            }

            if ($lockedRequest->email_verified_at === null) {
                throw ValidationException::withMessages(['request' => 'The teacher must verify their email before counselor review.']);
            }

            $photoPath = $lockedRequest->id_photo_path;
            $lockedRequest->forceFill([
                'status' => TeacherAccountRequest::DECLINED,
                'password' => null,
                'reviewed_by' => $request->user()->getKey(),
                'reviewed_at' => now(),
                'decision_message' => filled($validated['decision_message'] ?? null)
                    ? trim($validated['decision_message'])
                    : null,
            ])->save();

            return [$lockedRequest->refresh(), $photoPath];
        });

        $photoDeleted = $this->deleteIdPhoto($teacherRequest, $photoPath);
        $emailSent = $this->sendDecisionEmail($teacherRequest, false);

        return to_route('admin.users.index')->with(
            'status',
            'Teacher account request declined.'
                .($emailSent ? ' A decline email was sent with instructions to resubmit.' : ' The decline email could not be sent; check the mail configuration.')
                .($photoDeleted ? ' The ID photo was deleted.' : ' The ID photo could not be deleted; contact the system administrator.'),
        );
    }

    private function sendVerificationEmail(TeacherAccountRequest $teacherRequest): void
    {
        Notification::route('mail', $teacherRequest->email)
            ->notify(new TeacherAccountRequestVerification($teacherRequest));
    }

    private function sendDecisionEmail(TeacherAccountRequest $teacherRequest, bool $approved): bool
    {
        try {
            Notification::route('mail', $teacherRequest->email)->notify(
                new TeacherAccountRequestDecision(
                    $teacherRequest->name,
                    $approved,
                    $teacherRequest->decision_message,
                ),
            );
        } catch (TransportExceptionInterface $exception) {
            Log::error('Teacher account request decision email could not be sent.', [
                'teacher_account_request_id' => $teacherRequest->id,
                'approved' => $approved,
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }

    private function deleteIdPhoto(TeacherAccountRequest $teacherRequest, ?string $photoPath): bool
    {
        if (! is_string($photoPath) || $photoPath === '') {
            return true;
        }

        $disk = Storage::disk('local');
        if ($disk->exists($photoPath) && ! $disk->delete($photoPath)) {
            Log::error('Teacher account request ID photo could not be deleted after review.', [
                'teacher_account_request_id' => $teacherRequest->id,
            ]);

            return false;
        }

        $teacherRequest->forceFill([
            'id_photo_path' => null,
            'id_photo_mime_type' => null,
        ])->save();

        return true;
    }
}
