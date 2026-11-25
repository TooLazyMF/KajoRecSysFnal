<?php

namespace App\Http\Controllers;

use App\Models\RosterImportState;
use App\Models\SlipSubmission;
use App\Models\StudentReference;
use App\Models\TeacherAccountRequest;
use App\Models\TeacherReference;
use App\Models\User;
use App\Notifications\TeacherAccountCreated;
use App\Rules\UniqueEncryptedLookup;
use App\Support\SensitiveLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class UserManagementController extends Controller
{
    public function importStudents(Request $request): RedirectResponse
    {
        $request->validate([
            'student_file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
        ]);

        $file = $request->file('student_file');
        $fileRows = strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->readXlsxRows($file->getRealPath())
            : $this->readCsvRows($file->getRealPath());

        [$headerIndex, $headerMap] = $this->findStudentFileHeader($fileRows);
        $headers = $fileRows[$headerIndex] ?? [];
        $fileRows = array_slice($fileRows, $headerIndex + 1);
        $required = ['id_number', 'gender', 'year', 'course'];
        $missing = array_diff($required, array_keys($headerMap));

        $hasNameColumns = isset($headerMap['name'])
            || (isset($headerMap['first_name']) && isset($headerMap['last_name']));

        if ($missing !== [] || ! $hasNameColumns) {
            throw ValidationException::withMessages([
                'student_file' => 'The file must include Code, name columns, Sex, Year, and Course columns.',
            ]);
        }

        $rows = [];
        foreach ($fileRows as $rowIndex => $values) {
            if (count(array_filter($values, fn ($value) => filled(trim((string) $value)))) === 0) {
                continue;
            }

            $sourceData = [];
            foreach ($headers as $index => $header) {
                $sourceData[trim((string) $header)] = trim((string) ($values[$index] ?? ''));
            }

            $idNumber = StudentReference::normalizeIdNumber($values[$headerMap['id_number']] ?? '');
            $name = $this->studentNameFromRow($values, $headerMap);
            $gender = $this->normalizeGender($values[$headerMap['gender']] ?? '');

            if ($idNumber === '' || $name === '' || $gender === null) {
                continue;
            }

            $year = $this->valueFromHeader($values, $headerMap, 'year');
            if ($year === null) {
                throw ValidationException::withMessages([
                    'student_file' => "Year level is required for every student record. Missing year level for {$name} (ID {$idNumber}) on data row ".($rowIndex + $headerIndex + 2).'.',
                ]);
            }

            $rows[$idNumber] = [
                'id_number' => Crypt::encryptString($idNumber),
                'id_number_lookup_hash' => SensitiveLookup::hash('id_number', $idNumber),
                'name' => Crypt::encryptString($name),
                'gender' => Crypt::encryptString($gender),
                'year' => Crypt::encryptString($this->normalizeYearLevel($year)),
                'course' => Crypt::encryptString((string) $this->valueFromHeader($values, $headerMap, 'course')),
                'source_data' => json_encode(Crypt::encryptString(json_encode($sourceData, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'student_file' => 'No valid student records were found in the file.',
            ]);
        }

        $isUpdate = RosterImportState::isImported(RosterImportState::STUDENTS);

        DB::transaction(function () use ($rows): void {
            $ids = array_values(array_filter(array_map(
                fn (string $id): ?string => SensitiveLookup::hash('id_number', $id),
                array_keys($rows),
            )));

            StudentReference::query()->upsert(
                array_values($rows),
                ['id_number_lookup_hash'],
                ['name', 'gender', 'year', 'course', 'source_data', 'updated_at']
            );

            // The uploaded file is the complete roster for the current school year.
            StudentReference::query()->whereNotIn('id_number_lookup_hash', $ids)->delete();

            $studentUsers = User::query()
                ->where('role', 'student')
                ->whereNotNull('id_number')
                ->get(['id', 'id_number']);
            $studentUsersByNumber = $studentUsers->groupBy(
                fn (User $user) => StudentReference::normalizeIdNumber($user->id_number)
            );

            foreach ($rows as $idNumber => $row) {
                $matchingUserIds = $studentUsersByNumber->get($idNumber, collect())->pluck('id');
                if ($matchingUserIds->isNotEmpty()) {
                    User::query()->whereKey($matchingUserIds)->get()->each(function (User $user) use ($row): void {
                        $user->forceFill([
                            'name' => Crypt::decryptString($row['name']),
                            'gender' => Crypt::decryptString($row['gender']),
                            'is_active' => true,
                            'is_graduated' => false,
                        ])->save();
                    });
                }
            }

            $missingUserIds = $studentUsers
                ->reject(fn (User $user) => in_array(
                    SensitiveLookup::hash('id_number', StudentReference::normalizeIdNumber($user->id_number)),
                    $ids,
                    true,
                ))
                ->pluck('id');
            if ($missingUserIds->isNotEmpty()) {
                User::query()
                    ->whereKey($missingUserIds)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            RosterImportState::markImported(RosterImportState::STUDENTS);
        });

        return to_route('guidance.dashboard')->with('status', count($rows).($isUpdate
            ? ' student records updated. Accounts missing from the file were deactivated and moved to the archived files folder.'
            : ' student records imported successfully. Future uploads will update this roster automatically.'));
    }

    public function importTeachers(Request $request): RedirectResponse
    {
        $request->validate([
            'teacher_file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
        ]);

        $file = $request->file('teacher_file');
        $fileRows = strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? $this->readXlsxRows($file->getRealPath(), 'teacher_file')
            : $this->readCsvRows($file->getRealPath(), 'teacher_file');

        $rows = [];
        $hasSeenFirstName = false;

        foreach ($fileRows as $rowIndex => $values) {
            $cells = array_map(fn ($value) => trim((string) $value, " \t\r\n\xEF\xBB\xBF"), $values);
            $names = array_values(array_filter($cells, fn (string $value) => $value !== ''));

            if ($names === []) {
                continue;
            }

            $header = preg_replace('/[^a-z0-9]/', '', strtolower($names[0])) ?? '';
            if (! $hasSeenFirstName) {
                $hasSeenFirstName = true;
                if (count($names) === 1 && in_array($header, [
                    'name',
                    'fullname',
                    'teachername',
                    'teachernames',
                    'teachersname',
                    'teacherfullname',
                    'teachersfullname',
                    'nameofteacher',
                ], true)) {
                    continue;
                }
            }

            if (count($names) !== 1) {
                throw ValidationException::withMessages([
                    'teacher_file' => 'The teacher file must contain one teacher name per row, in a single column.',
                ]);
            }

            $name = $names[0];
            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages([
                    'teacher_file' => 'Teacher names must be 255 characters or fewer.',
                ]);
            }

            $normalizedName = TeacherReference::normalizeName($name);
            if ($normalizedName === '') {
                throw ValidationException::withMessages([
                    'teacher_file' => 'The teacher file contains an invalid name on row '.($rowIndex + 1).'.',
                ]);
            }

            $lookupHash = SensitiveLookup::hash('name', $normalizedName);
            $rows[$lookupHash] = [
                'name' => Crypt::encryptString($name),
                'name_lookup_hash' => $lookupHash,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'teacher_file' => 'No teacher names were found in the file.',
            ]);
        }

        $isUpdate = RosterImportState::isImported(RosterImportState::TEACHERS);

        DB::transaction(function () use ($rows): void {
            TeacherReference::query()->upsert(
                array_values($rows),
                ['name_lookup_hash'],
                ['name', 'updated_at'],
            );
            TeacherReference::query()
                ->whereNotIn('name_lookup_hash', array_keys($rows))
                ->delete();

            RosterImportState::markImported(RosterImportState::TEACHERS);
        });

        return to_route('guidance.dashboard')->with('status', count($rows).($isUpdate
            ? ' teacher names updated. Teacher account requests must use a listed name.'
            : ' teacher names imported. Teacher account requests must use a listed name.'));
    }

    /** @return array<int, array<int, string>> */
    private function readCsvRows(string $path, string $errorKey = 'student_file'): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([$errorKey => 'The file could not be read.']);
        }

        try {
            $headers = fgetcsv($handle) ?: [];
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                $rows[] = array_map(fn ($value) => (string) $value, $values);
            }

            array_unshift($rows, array_map(fn ($header) => (string) $header, $headers));

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<int, array<int, string>> */
    private function readXlsxRows(string $path, string $errorKey = 'student_file'): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([$errorKey => 'The Excel file could not be read.']);
        }

        try {
            $sheetName = collect(range(0, $zip->numFiles - 1))
                ->map(fn (int $index) => $zip->getNameIndex($index))
                ->first(fn (string $name) => preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $name) === 1);
            $sheetXml = $sheetName ? $zip->getFromName($sheetName) : false;

            if ($sheetXml === false) {
                throw ValidationException::withMessages([$errorKey => 'The Excel file has no readable worksheet.']);
            }

            $sharedStrings = [];
            if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $shared = simplexml_load_string($sharedXml);
                if ($shared !== false) {
                    foreach ($shared->xpath('//*[local-name() = "si"]') ?: [] as $item) {
                        $sharedStrings[] = implode('', $this->xmlTextValues($item));
                    }
                }
            }

            $sheet = simplexml_load_string($sheetXml);
            if ($sheet === false) {
                throw ValidationException::withMessages([$errorKey => 'The Excel worksheet could not be read.']);
            }

            $rows = [];
            foreach ($sheet->xpath('//*[local-name() = "sheetData"]/*[local-name() = "row"]') ?: [] as $row) {
                $values = [];
                foreach ($row->xpath('./*[local-name() = "c"]') ?: [] as $cell) {
                    $column = $this->xlsxColumnIndex((string) $cell['r']);
                    $type = (string) $cell['t'];
                    $inlineText = $cell->xpath('./*[local-name() = "is"]');
                    $valueNodes = $cell->xpath('./*[local-name() = "v"]');
                    $value = $type === 'inlineStr'
                        ? implode('', $this->xmlTextValues($inlineText[0] ?? $cell))
                        : (string) ($valueNodes[0] ?? '');

                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    }

                    $values[$column] = $value;
                }

                if ($values !== [] && count(array_filter($values, fn ($value) => filled(trim((string) $value)))) > 0) {
                    $maxColumn = max(array_keys($values));
                    $rows[] = array_map(fn ($index) => (string) ($values[$index] ?? ''), range(0, $maxColumn));
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function xmlTextValues(\SimpleXMLElement $xml): array
    {
        $texts = $xml->xpath('.//*[local-name() = "t"]') ?: [];

        return array_map(fn ($text) => (string) $text, $texts);
    }

    private function xlsxColumnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }

        return $index - 1;
    }

    /** @param array<int, string|null> $headers */
    private function studentFileHeaderMap(array $headers): array
    {
        $aliases = [
            'id_number' => ['id', 'idno', 'idnum', 'idnumber', 'code', 'studentcode', 'studentid', 'studentno', 'studentnumber', 'studentidnumber'],
            'name' => ['name', 'fullname', 'studentname', 'studentfullname'],
            'first_name' => ['firstname', 'givenname'],
            'last_name' => ['lastname', 'surname', 'familyname'],
            'middle_name' => ['middlename', 'middleinitial'],
            'gender' => ['gender', 'sex'],
            'year' => ['year', 'years', 'yr', 'yrlevel', 'yearlevel', 'level', 'yearofstudy', 'academicyearlevel', 'currentyear', 'currentyearlevel'],
            'course' => ['course', 'program', 'degree', 'courseprogram', 'programname', 'degreeprogram'],
        ];
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $header, " \t\r\n\xEF\xBB\xBF"))) ?? '';
            foreach ($aliases as $field => $fieldAliases) {
                $isYearHeader = $field === 'year'
                    && ! str_starts_with($normalized, 'schoolyear')
                    && (str_starts_with($normalized, 'year') || str_starts_with($normalized, 'yr'));
                $isCourseHeader = $field === 'course'
                    && (str_contains($normalized, 'course') || str_contains($normalized, 'program'));

                if (in_array($normalized, $fieldAliases, true) || $isYearHeader || $isCourseHeader) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array{0: int, 1: array<string, int>}
     */
    private function findStudentFileHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $headerMap = $this->studentFileHeaderMap($row);

            if (
                isset($headerMap['id_number'], $headerMap['gender'], $headerMap['year'], $headerMap['course'])
                && (isset($headerMap['name']) || (isset($headerMap['first_name'], $headerMap['last_name'])))
            ) {
                return [$index, $headerMap];
            }
        }

        return [0, $this->studentFileHeaderMap($rows[0] ?? [])];
    }

    /** @param array<int, string> $values @param array<string, int> $headerMap */
    private function studentNameFromRow(array $values, array $headerMap): string
    {
        if (isset($headerMap['name'])) {
            return trim((string) ($values[$headerMap['name']] ?? ''));
        }

        return implode(' ', array_filter([
            $this->valueFromHeader($values, $headerMap, 'first_name'),
            $this->valueFromHeader($values, $headerMap, 'middle_name'),
            $this->valueFromHeader($values, $headerMap, 'last_name'),
        ]));
    }

    /** @param array<int, string> $values @param array<string, int> $headerMap */
    private function valueFromHeader(array $values, array $headerMap, string $field): ?string
    {
        return isset($headerMap[$field]) && filled($value = trim((string) ($values[$headerMap[$field]] ?? '')))
            ? $value
            : null;
    }

    private function normalizeYearLevel(string $year): string
    {
        $year = trim($year);
        if (! ctype_digit($year) || (int) $year < 1 || (int) $year > 8) {
            return $year;
        }

        $number = (int) $year;
        $suffix = match (true) {
            $number % 100 >= 11 && $number % 100 <= 13 => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix.' Year';
    }

    private function normalizeGender(string $gender): ?string
    {
        return match (strtolower(trim($gender))) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => null,
        };
    }

    public function index(): View
    {
        $studentReferences = StudentReference::query()
            ->get(['id_number', 'course', 'year'])
            ->keyBy(fn (StudentReference $reference) => StudentReference::normalizeIdNumber($reference->id_number));

        $students = User::query()
            ->where('role', 'student')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'id_number', 'gender', 'is_active', 'is_graduated']);

        $studentCifs = SlipSubmission::query()
            ->whereIn('user_id', $students->pluck('id'))
            ->where('slip', 'qf-bo-oss-04-client-information-form')
            ->latest()
            ->get()
            ->unique('user_id')
            ->mapWithKeys(fn (SlipSubmission $submission) => [$submission->user_id => $submission->data?->getArrayCopy() ?? []]);

        $teachers = User::query()
            ->where('role', 'teacher')
            ->withExists(['accountSessionLogs as has_logged_in'])
            ->orderBy('id')
            ->paginate(25);
        $teacherRequests = TeacherAccountRequest::query()
            ->where('status', TeacherAccountRequest::PENDING)
            ->latest()
            ->get();

        return view('admin.users', compact(
            'students',
            'studentReferences',
            'studentCifs',
            'teachers',
            'teacherRequests',
        ));
    }

    public function editTeacher(User $user): View
    {
        abort_unless($user->role === 'teacher', 404);
        abort_if($user->accountSessionLogs()->exists(), 403, 'Teacher account information is locked after the teacher signs in.');

        $nameParts = preg_split('/\s+/', trim($user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = array_shift($nameParts) ?? '';
        $lastName = count($nameParts) > 0 ? array_pop($nameParts) : '';
        $middleInitial = trim(implode(' ', $nameParts));

        return view('admin.teacher-edit', compact('user', 'firstName', 'middleInitial', 'lastName'));
    }

    public function updateTeacher(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'teacher', 404);
        abort_if($user->accountSessionLogs()->exists(), 403, 'Teacher account information is locked after the teacher signs in.');

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['required', 'string', 'max:5', 'regex:/^[A-Za-z](?:\.)?$/'],
            'teacher_id' => ['required', 'string', 'max:255', new UniqueEncryptedLookup('users', 'id_number', $user->id)],
            'email' => ['required', 'email', 'max:255', new UniqueEncryptedLookup('users', 'email', $user->id)],
            'counselor_password' => ['required', 'string'],
        ]);

        $counselor = $request->user();
        if (! $counselor instanceof User || ! Hash::check($validated['counselor_password'], $counselor->getAuthPassword())) {
            return back()->withErrors(['counselor_password' => 'The counselor password is incorrect.'])->withInput();
        }

        $emailChanged = strtolower((string) $user->email) !== strtolower($validated['email']);
        $user->forceFill([
            'name' => trim($validated['first_name'].' '.$validated['middle_initial'].' '.$validated['last_name']),
            'email' => $validated['email'],
            'id_number' => $validated['teacher_id'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            'is_active' => $emailChanged ? false : $user->is_active,
        ])->save();

        $verificationSent = true;
        if ($emailChanged || ! $user->hasVerifiedEmail()) {
            try {
                $user->notify(new TeacherAccountCreated);
            } catch (Throwable $exception) {
                report($exception);
                $verificationSent = false;
            }
        }

        return to_route('admin.users.index')->with(
            'status',
            $verificationSent
                ? 'Teacher account information updated successfully.'
                : 'Teacher information was updated, but the verification email could not be sent. Please check your internet connection and edit the account to retry.'
        );
    }

    public function verifyCreatedTeacher(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'teacher', 404);

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill([
                'email_verified_at' => now(),
                'is_active' => true,
            ])->save();
        }

        return to_route('login')->with('status', 'Your KajoRec teacher account is verified and active. You may now sign in.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, ['student', 'teacher'], true), 404);

        $validated = $request->validate([
            'action' => [
                'required',
                Rule::in($user->role === 'student'
                    ? ['activate', 'deactivate', 'graduate']
                    : ['activate', 'deactivate']),
            ],
        ]);

        match ($validated['action']) {
            'activate' => $user->forceFill(['is_active' => true])->save(),
            'deactivate' => $user->forceFill(['is_active' => false])->save(),
            'graduate' => $user->forceFill(['is_graduated' => true, 'is_active' => false])->save(),
        };

        return to_route('admin.users.index')->with('status', 'User updated successfully.');
    }
}
