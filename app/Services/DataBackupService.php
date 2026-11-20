<?php

namespace App\Services;

use App\Exceptions\InvalidDataBackupException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class DataBackupService
{
    private const FORMAT = 'kajorec-database-backup';

    private const VERSION = 4;

    private const DATA_FILE = 'database.json';

    private const TABLES = [
        'users',
        'slip_informations',
        'slip_submissions',
        'slip_assignments',
        'appointments',
        'student_references',
        'account_session_logs',
        'passkeys',
        'teacher_references',
        'roster_import_states',
    ];

    private const MAX_ARCHIVE_ENTRIES = 10001;

    private const MAX_UPLOAD_BYTES = 104857600;

    private const MAX_UNCOMPRESSED_BYTES = 209715200;

    private const MAX_FILE_BYTES = 26214400;

    /**
     * Create a KajoRec backup archive and return its temporary path.
     */
    public function createBackup(): string
    {
        $tables = [];
        $schema = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot create a backup because the {$table} table is missing.");
            }

            $schema[$table] = Schema::getColumnListing($table);
            $tables[$table] = DB::table($table)->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        }

        $fileManifest = [];
        $missingFiles = [];
        $zipPath = tempnam(sys_get_temp_dir(), 'kajorec-backup-');

        if ($zipPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the backup.');
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Unable to create the KajoRec backup archive.');
        }

        try {
            $uncompressedBytes = 0;

            foreach ($this->referencedFilePaths($tables) as $path) {
                if (! Storage::disk('local')->exists($path)) {
                    $missingFiles[] = $path;

                    continue;
                }

                $absolutePath = Storage::disk('local')->path($path);
                $fileSize = filesize($absolutePath);

                if ($fileSize === false || $fileSize > self::MAX_FILE_BYTES) {
                    throw new RuntimeException("A referenced KajoRec file exceeds the backup size limit: {$path}");
                }

                $hash = hash_file('sha256', $absolutePath);

                if ($hash === false) {
                    throw new RuntimeException("Unable to read a referenced KajoRec file: {$path}");
                }

                $fileManifest[$path] = $hash;
                $entry = "files/{$hash}";

                if (! $this->archiveContains($zip, $entry)) {
                    $uncompressedBytes += $fileSize;

                    if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES || ! $zip->addFile($absolutePath, $entry)) {
                        throw new RuntimeException("Unable to add a referenced KajoRec file to the backup: {$path}");
                    }
                }
            }

            $payload = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'backup_id' => (string) Str::uuid(),
                'generated_at' => now()->toIso8601String(),
                'schema' => $schema,
                'tables' => $tables,
                'files' => $fileManifest,
                'missing_files' => $missingFiles,
            ];

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (strlen($json) + $uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES
                || ! $zip->addFromString(self::DATA_FILE, $json)) {
                throw new RuntimeException('Unable to add database records to the KajoRec backup.');
            }

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finish the KajoRec backup archive.');
            }

            $archiveSize = filesize($zipPath);

            if ($archiveSize === false || $archiveSize > self::MAX_UPLOAD_BYTES) {
                throw new RuntimeException('The KajoRec backup exceeds the maximum import size.');
            }
        } catch (Throwable $exception) {
            $zip->close();
            @unlink($zipPath);

            throw $exception;
        }

        return $zipPath;
    }

    /**
     * Validate and merge an uploaded KajoRec backup.
     *
     * @return array{added: array<string, int>}
     */
    public function mergeBackup(UploadedFile $upload): array
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($upload->getRealPath());

        if ($openResult !== true) {
            throw new InvalidDataBackupException('The uploaded file is not a readable KajoRec backup.');
        }

        $createdFiles = [];

        try {
            $payload = $this->readAndValidateArchive($zip);
            $filePathMap = $this->restoreFiles($zip, $payload, $createdFiles);

            try {
                $result = DB::transaction(function () use ($payload, $filePathMap): array {
                    return $this->mergeTables($payload, $filePathMap);
                });
            } catch (Throwable $exception) {
                foreach ($createdFiles as $path) {
                    Storage::disk('local')->delete($path);
                }

                throw $exception;
            }

            return $result;
        } catch (Throwable $exception) {
            foreach ($createdFiles as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        } finally {
            $zip->close();
        }
    }

    private function readAndValidateArchive(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidDataBackupException('The backup archive contains an invalid number of files.');
        }

        $totalBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || ! isset($stat['name'], $stat['size']) || str_ends_with($stat['name'], '/')) {
                throw new InvalidDataBackupException('The backup archive contains an invalid file entry.');
            }

            $totalBytes += (int) $stat['size'];

            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidDataBackupException('The backup archive is too large to import.');
            }
        }

        $databaseJson = $zip->getFromName(self::DATA_FILE);

        if ($databaseJson === false) {
            throw new InvalidDataBackupException('The archive does not contain a KajoRec database backup.');
        }

        try {
            $payload = json_decode($databaseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidDataBackupException('The backup database file is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)
            || ($payload['format'] ?? null) !== self::FORMAT
            || ! in_array($payload['version'] ?? null, [1, 2, 3, self::VERSION], true)
            || ! isset($payload['backup_id'])
            || ! Str::isUuid($payload['backup_id'])
            || ! isset($payload['schema'], $payload['tables'], $payload['files'], $payload['missing_files'])
            || ! is_array($payload['schema'])
            || ! is_array($payload['tables'])
            || ! is_array($payload['files'])
            || ! is_array($payload['missing_files'])) {
            throw new InvalidDataBackupException('This file is not a supported KajoRec backup.');
        }

        if ($payload['version'] === 1) {
            $payload = $this->normalizeVersionOnePayload($payload);
        } elseif ($payload['version'] === 2) {
            $payload = $this->normalizeVersionTwoPayload($payload);
        } elseif ($payload['version'] === 3) {
            $payload = $this->normalizeVersionThreePayload($payload);
        }

        if (array_keys($payload['tables']) !== self::TABLES
            || array_keys($payload['schema']) !== self::TABLES) {
            throw new InvalidDataBackupException('The backup does not contain the expected KajoRec database tables.');
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)
                || ! is_array($payload['schema'][$table])
                || $payload['schema'][$table] !== Schema::getColumnListing($table)
                || ! is_array($payload['tables'][$table])) {
                throw new InvalidDataBackupException('The backup database schema is incompatible with this KajoRec installation.');
            }

            $expectedColumns = $payload['schema'][$table];

            foreach ($payload['tables'][$table] as $row) {
                if (! is_array($row)
                    || array_diff($expectedColumns, array_keys($row)) !== []
                    || array_diff(array_keys($row), $expectedColumns) !== []
                    || ! isset($row['id'])
                    || ! is_numeric($row['id'])) {
                    throw new InvalidDataBackupException("The backup contains an invalid row in the {$table} table.");
                }

                if ($table === 'users'
                    && ! in_array($row['role'] ?? null, ['student', 'teacher', 'guidance_counselor'], true)) {
                    throw new InvalidDataBackupException('The backup contains an unsupported user role.');
                }

                foreach ($row as $value) {
                    if (! is_scalar($value) && $value !== null) {
                        throw new InvalidDataBackupException("The backup contains invalid data in the {$table} table.");
                    }
                }
            }
        }

        if (count(array_unique($payload['missing_files'])) !== count($payload['missing_files'])
            || array_intersect(array_keys($payload['files']), $payload['missing_files']) !== []) {
            throw new InvalidDataBackupException('The backup file manifest contains conflicting entries.');
        }

        $allowedEntries = [self::DATA_FILE];

        foreach ($payload['files'] as $path => $hash) {
            if (! is_string($path) || ! $this->isSafeRelativePath($path)
                || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new InvalidDataBackupException('The backup contains an invalid file manifest.');
            }

            $allowedEntries[] = "files/{$hash}";
        }

        foreach ($payload['missing_files'] as $path) {
            if (! is_string($path) || ! $this->isSafeRelativePath($path)) {
                throw new InvalidDataBackupException('The backup contains an invalid missing-file list.');
            }
        }

        $allowedEntries = array_values(array_unique($allowedEntries));
        $archiveEntries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->getNameIndex($index);

            if (! is_string($entry) || ! in_array($entry, $allowedEntries, true)) {
                throw new InvalidDataBackupException('The archive contains files that are not part of a KajoRec backup.');
            }

            $archiveEntries[] = $entry;
        }

        if (count($archiveEntries) !== count($allowedEntries)
            || count(array_unique($archiveEntries)) !== count($archiveEntries)) {
            throw new InvalidDataBackupException('The backup archive is incomplete or contains duplicate entries.');
        }

        $referencedPaths = $this->referencedFilePaths($payload['tables']);
        $declaredPaths = array_unique([...array_keys($payload['files']), ...$payload['missing_files']]);

        if (array_diff($referencedPaths, $declaredPaths) !== []) {
            throw new InvalidDataBackupException('The backup file manifest is incomplete.');
        }

        return $payload;
    }

    private function normalizeVersionOnePayload(array $payload): array
    {
        $tables = [];
        $schema = [];

        foreach (self::TABLES as $table) {
            $legacyTable = $table === 'slip_informations' ? 'cns_requests' : $table;

            if (array_key_exists($legacyTable, $payload['tables'])) {
                $tables[$table] = $payload['tables'][$legacyTable];
            }

            if (array_key_exists($legacyTable, $payload['schema'])) {
                $schema[$table] = $payload['schema'][$legacyTable];
            }
        }

        if (isset($schema['slip_assignments']) && is_array($schema['slip_assignments'])) {
            $schema['slip_assignments'] = array_map(
                static fn (string $column): string => $column === 'cns_request_id' ? 'slip_information_id' : $column,
                $schema['slip_assignments'],
            );
        }

        if (isset($tables['slip_assignments']) && is_array($tables['slip_assignments'])) {
            foreach ($tables['slip_assignments'] as &$assignment) {
                if (is_array($assignment) && array_key_exists('cns_request_id', $assignment)) {
                    $assignment['slip_information_id'] = $assignment['cns_request_id'];
                    unset($assignment['cns_request_id']);
                }
            }
            unset($assignment);
        }

        $tables['teacher_references'] = [];
        $schema['teacher_references'] = Schema::getColumnListing('teacher_references');
        $tables['roster_import_states'] = [];
        $schema['roster_import_states'] = Schema::getColumnListing('roster_import_states');

        $payload['version'] = self::VERSION;
        $payload['tables'] = $tables;
        $payload['schema'] = $schema;

        return $payload;
    }

    private function normalizeVersionTwoPayload(array $payload): array
    {
        $payload['tables']['teacher_references'] ??= [];
        $payload['schema']['teacher_references'] ??= Schema::getColumnListing('teacher_references');
        $payload['tables']['roster_import_states'] = [];
        $payload['schema']['roster_import_states'] = Schema::getColumnListing('roster_import_states');
        $payload['version'] = self::VERSION;

        return $payload;
    }

    private function normalizeVersionThreePayload(array $payload): array
    {
        $payload['tables']['roster_import_states'] = [];
        $payload['schema']['roster_import_states'] = Schema::getColumnListing('roster_import_states');
        $payload['version'] = self::VERSION;

        return $payload;
    }

    /**
     * @param  list<string>  $createdFiles
     * @return array<string, string>
     */
    private function restoreFiles(ZipArchive $zip, array $payload, array &$createdFiles): array
    {
        $pathMap = [];

        foreach ($payload['files'] as $originalPath => $hash) {
            $entry = "files/{$hash}";
            $stat = $zip->statName($entry);

            if ($stat === false || $stat['size'] > self::MAX_FILE_BYTES) {
                throw new InvalidDataBackupException('A file in the backup is missing or too large to restore.');
            }

            $contents = $zip->getFromName($entry);

            if ($contents === false || ! hash_equals($hash, hash('sha256', $contents))) {
                throw new InvalidDataBackupException('A file in the backup failed its integrity check.');
            }

            $disk = Storage::disk('local');
            $existingOriginalPath = $disk->exists($originalPath)
                ? hash_file('sha256', $disk->path($originalPath))
                : false;

            if ($existingOriginalPath === $hash) {
                $pathMap[$originalPath] = $originalPath;

                continue;
            }

            $extension = pathinfo($originalPath, PATHINFO_EXTENSION);
            $extension = preg_match('/^[a-zA-Z0-9]{1,10}$/D', $extension) ? '.'.$extension : '';
            $restoredPath = "kajorec-imports/{$hash}{$extension}";

            if ($disk->exists($restoredPath)) {
                $existingHash = hash_file('sha256', $disk->path($restoredPath));

                if ($existingHash !== $hash) {
                    throw new RuntimeException('A stored KajoRec import file failed its integrity check.');
                }
            } else {
                if (! $disk->put($restoredPath, $contents)) {
                    throw new RuntimeException('Unable to store a file from the KajoRec backup.');
                }

                $createdFiles[] = $restoredPath;
            }

            $pathMap[$originalPath] = $restoredPath;
        }

        foreach ($payload['missing_files'] as $missingPath) {
            $pathMap[$missingPath] = "kajorec-missing/{$payload['backup_id']}/{$missingPath}";
        }

        return $pathMap;
    }

    /**
     * @return array{added: array<string, int>}
     */
    private function mergeTables(array $payload, array $filePathMap): array
    {
        $backupId = $payload['backup_id'];
        $idMaps = ['users' => []];
        $added = array_fill_keys(self::TABLES, 0);

        $users = $payload['tables']['users'];

        foreach ($users as $user) {
            $sourceId = (string) $user['id'];
            $mappedId = $this->mappedRecordId($backupId, 'users', $sourceId);

            if ($mappedId === null) {
                $matchedUserId = $this->findMatchingUser($user);

                if ($matchedUserId !== null) {
                    $matchedRole = DB::table('users')->where('id', $matchedUserId)->value('role');

                    if ($matchedRole !== $user['role']) {
                        throw new InvalidDataBackupException('An imported account matches an existing account with a different role.');
                    }

                    $mappedId = $matchedUserId;
                } else {
                    if (! in_array($user['role'], ['student', 'teacher', 'guidance_counselor'], true)) {
                        throw new InvalidDataBackupException('The backup contains an unsupported user role.');
                    }

                    $newUser = $user;
                    unset($newUser['id']);
                    $mappedId = (int) DB::table('users')->insertGetId($newUser);
                    $added['users']++;
                }

                $this->saveRecordMapping($backupId, 'users', $sourceId, $mappedId);
            }

            $idMaps['users'][$sourceId] = $mappedId;
        }

        $this->mergeTable($backupId, 'slip_informations', $payload['tables']['slip_informations'], [
            'user_id' => 'users',
            'approved_by' => 'users',
        ], $idMaps, $filePathMap, $added);

        $this->mergeTable($backupId, 'slip_submissions', $payload['tables']['slip_submissions'], [
            'user_id' => 'users',
            'approved_by' => 'users',
        ], $idMaps, $filePathMap, $added);

        $pendingAssignments = $payload['tables']['slip_assignments'];

        while ($pendingAssignments !== []) {
            $ready = [];
            $pending = [];

            foreach ($pendingAssignments as $assignment) {
                $parentId = $assignment['related_assignment_id'];

                if ($parentId === null || isset($idMaps['slip_assignments'][(string) $parentId])) {
                    $ready[] = $assignment;
                } else {
                    $pending[] = $assignment;
                }
            }

            if ($ready === []) {
                throw new InvalidDataBackupException('The backup contains a referral with a missing or circular parent record.');
            }

            $this->mergeTable($backupId, 'slip_assignments', $ready, [
                'assigned_by' => 'users',
                'student_id' => 'users',
                'related_assignment_id' => 'slip_assignments',
                'slip_submission_id' => 'slip_submissions',
                'slip_information_id' => 'slip_informations',
                'approved_by' => 'users',
            ], $idMaps, $filePathMap, $added);

            $pendingAssignments = $pending;
        }

        $this->mergeTable($backupId, 'appointments', $payload['tables']['appointments'], [
            'user_id' => 'users',
            'reviewed_by' => 'users',
            'referral_id' => 'slip_assignments',
        ], $idMaps, $filePathMap, $added);

        $this->mergeTable($backupId, 'student_references', $payload['tables']['student_references'], [], $idMaps, $filePathMap, $added, [
            ['id_number_lookup_hash'],
        ]);

        $this->mergeTable($backupId, 'teacher_references', $payload['tables']['teacher_references'], [], $idMaps, $filePathMap, $added, [
            ['name_lookup_hash'],
        ]);

        $this->mergeTable($backupId, 'roster_import_states', $payload['tables']['roster_import_states'], [], $idMaps, $filePathMap, $added, [
            ['roster_type'],
        ]);

        $this->mergeTable($backupId, 'account_session_logs', $payload['tables']['account_session_logs'], [
            'user_id' => 'users',
        ], $idMaps, $filePathMap, $added, [
            ['session_hash'],
        ]);

        $this->mergeTable($backupId, 'passkeys', $payload['tables']['passkeys'], [
            'user_id' => 'users',
        ], $idMaps, $filePathMap, $added, [
            ['credential_id'],
        ]);

        return ['added' => $added];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $foreignKeys
     * @param  array<string, array<string, int>>  $idMaps
     * @param  list<list<string>>  $uniqueKeys
     * @param  array<string, int>  $added
     */
    private function mergeTable(
        string $backupId,
        string $table,
        array $rows,
        array $foreignKeys,
        array &$idMaps,
        array $filePathMap,
        array &$added,
        array $uniqueKeys = [],
    ): void {
        $idMaps[$table] ??= [];
        $existingRows = DB::table($table)->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
        $signatureIndex = [];

        foreach ($existingRows as $existingRow) {
            $signatureIndex[$this->rowSignature($existingRow)] = (int) $existingRow['id'];
        }

        foreach ($rows as $sourceRow) {
            $sourceId = (string) $sourceRow['id'];
            $mappedId = $this->mappedRecordId($backupId, $table, $sourceId);

            if ($mappedId !== null) {
                $idMaps[$table][$sourceId] = $mappedId;

                continue;
            }

            $row = $this->remapForeignKeys($sourceRow, $foreignKeys, $idMaps);
            $row = $this->rewriteFileReferences($table, $row, $filePathMap);

            $mappedId = $this->findByUniqueKeys($table, $row, $uniqueKeys)
                ?? ($signatureIndex[$this->rowSignature($row)] ?? null);

            if ($mappedId === null) {
                unset($row['id']);
                $mappedId = (int) DB::table($table)->insertGetId($row);
                $added[$table]++;

                $row['id'] = $mappedId;
                $signatureIndex[$this->rowSignature($row)] = $mappedId;
            }

            $this->saveRecordMapping($backupId, $table, $sourceId, $mappedId);
            $idMaps[$table][$sourceId] = $mappedId;
        }
    }

    private function findMatchingUser(array $user): ?int
    {
        $emailHash = $user['email_lookup_hash'] ?? null;
        $idNumberHash = $user['id_number_lookup_hash'] ?? null;
        $byEmail = ! is_string($emailHash) || $emailHash === ''
            ? null
            : DB::table('users')->where('email_lookup_hash', $emailHash)->value('id');
        $byIdNumber = ! is_string($idNumberHash) || $idNumberHash === ''
            ? null
            : DB::table('users')
                ->where('id_number_lookup_hash', $idNumberHash)
                ->value('id');

        if ($byEmail !== null && $byIdNumber !== null && (int) $byEmail !== (int) $byIdNumber) {
            throw new InvalidDataBackupException('An imported account email and ID number match different existing accounts.');
        }

        return $byEmail !== null ? (int) $byEmail : ($byIdNumber !== null ? (int) $byIdNumber : null);
    }

    /**
     * @param  array<string, string>  $foreignKeys
     * @param  array<string, array<string, int>>  $idMaps
     */
    private function remapForeignKeys(array $row, array $foreignKeys, array $idMaps): array
    {
        foreach ($foreignKeys as $column => $referencedTable) {
            $sourceId = $row[$column] ?? null;

            if ($sourceId === null) {
                continue;
            }

            $mappedId = $idMaps[$referencedTable][(string) $sourceId] ?? null;

            if ($mappedId === null) {
                throw new InvalidDataBackupException("The backup contains a record with an unresolved {$column} reference.");
            }

            $row[$column] = $mappedId;
        }

        return $row;
    }

    /**
     * @param  list<list<string>>  $uniqueKeys
     */
    private function findByUniqueKeys(string $table, array $row, array $uniqueKeys): ?int
    {
        foreach ($uniqueKeys as $columns) {
            $query = DB::table($table);
            $hasNull = false;

            foreach ($columns as $column) {
                if (($row[$column] ?? null) === null) {
                    $hasNull = true;

                    break;
                }

                $query->where($column, $row[$column]);
            }

            if ($hasNull) {
                continue;
            }

            $id = $query->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        if ($table === 'slip_assignments' && ($row['related_assignment_id'] ?? null) !== null) {
            $id = DB::table($table)
                ->where('related_assignment_id', $row['related_assignment_id'])
                ->where('slip', $row['slip'])
                ->value('id');

            return $id === null ? null : (int) $id;
        }

        return null;
    }

    private function mappedRecordId(string $backupId, string $table, string $sourceId): ?int
    {
        $targetId = DB::table('backup_import_mappings')
            ->where('backup_id', $backupId)
            ->where('table_name', $table)
            ->where('source_record_id', $sourceId)
            ->value('target_record_id');

        if ($targetId === null || ! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where('id', $targetId)->exists() ? (int) $targetId : null;
    }

    private function saveRecordMapping(string $backupId, string $table, string $sourceId, int $targetId): void
    {
        DB::table('backup_import_mappings')->updateOrInsert([
            'backup_id' => $backupId,
            'table_name' => $table,
            'source_record_id' => $sourceId,
        ], [
            'target_record_id' => $targetId,
        ]);
    }

    private function rowSignature(array $row): string
    {
        unset($row['id']);
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function rewriteFileReferences(string $table, array $row, array $filePathMap): array
    {
        if (in_array($table, ['slip_informations', 'slip_submissions'], true)
            && isset($row['pdf_path'])
            && is_string($row['pdf_path'])) {
            $row['pdf_path'] = $filePathMap[$row['pdf_path']] ?? $row['pdf_path'];
        }

        $jsonColumns = match ($table) {
            'appointments' => ['attachments'],
            'slip_submissions' => ['data'],
            default => [],
        };

        foreach ($jsonColumns as $column) {
            if (! isset($row[$column]) || ! is_string($row[$column])) {
                continue;
            }

            [$value, $encrypted] = $this->decodeJsonColumn($table, $column, $row[$column]);
            $rewritten = $this->rewriteNestedPaths($value, $filePathMap);

            $rewrittenJson = json_encode($rewritten, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $row[$column] = match (true) {
                $encrypted && $rewritten === $value => $row[$column],
                $encrypted && $table === 'appointments' && $column === 'attachments' => json_encode(Crypt::encryptString($rewrittenJson), JSON_THROW_ON_ERROR),
                $encrypted => Crypt::encryptString($rewrittenJson),
                default => $rewrittenJson,
            };
        }

        return $row;
    }

    /**
     * @return array{0: array, 1: bool}
     */
    private function decodeJsonColumn(string $table, string $column, string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return [$decoded, false];
            }

            if (is_string($decoded)) {
                $decrypted = Crypt::decryptString($decoded);
                $decoded = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return [$decoded, true];
                }
            }
        } catch (JsonException) {
            // Encrypted JSON is not valid JSON until it is decrypted.
        } catch (DecryptException $exception) {
            throw new InvalidDataBackupException(
                "The backup contains invalid or incompatible encrypted JSON in {$table}.{$column}. Ensure the source and destination use the same APP_KEY.",
                previous: $exception,
            );
        }

        try {
            $decrypted = Crypt::decryptString($value);
            $decoded = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return [$decoded, true];
            }
        } catch (DecryptException|JsonException $exception) {
            throw new InvalidDataBackupException(
                "The backup contains invalid or incompatible encrypted JSON in {$table}.{$column}. Ensure the source and destination use the same APP_KEY.",
                previous: $exception,
            );
        }

        throw new InvalidDataBackupException("The backup contains invalid JSON in {$table}.{$column}.");
    }

    private function rewriteNestedPaths(mixed $value, array $filePathMap): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            if ($key === 'path' && is_string($nestedValue)) {
                $value[$key] = $filePathMap[$nestedValue] ?? $nestedValue;
            } else {
                $value[$key] = $this->rewriteNestedPaths($nestedValue, $filePathMap);
            }
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function referencedFilePaths(array $tables): array
    {
        $paths = [];

        foreach (['slip_informations', 'slip_submissions'] as $table) {
            foreach ($tables[$table] ?? [] as $row) {
                if (isset($row['pdf_path']) && is_string($row['pdf_path']) && $row['pdf_path'] !== '') {
                    $paths[] = $row['pdf_path'];
                }
            }
        }

        foreach ([
            ['appointments', 'attachments'],
            ['slip_submissions', 'data'],
        ] as [$table, $column]) {
            foreach ($tables[$table] ?? [] as $row) {
                if (! isset($row[$column]) || ! is_string($row[$column])) {
                    continue;
                }

                [$value] = $this->decodeJsonColumn($table, $column, $row[$column]);
                $this->collectNestedPaths($value, $paths);
            }
        }

        foreach ($paths as $path) {
            if (! $this->isSafeRelativePath($path)) {
                throw new InvalidDataBackupException('The backup contains an invalid file path.');
            }
        }

        return array_values(array_unique($paths));
    }

    private function collectNestedPaths(mixed $value, array &$paths): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nestedValue) {
            if ($key === 'path' && is_string($nestedValue) && $nestedValue !== '') {
                $paths[] = $nestedValue;
            } else {
                $this->collectNestedPaths($nestedValue, $paths);
            }
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function archiveContains(ZipArchive $zip, string $entry): bool
    {
        return $zip->locateName($entry) !== false;
    }
}
