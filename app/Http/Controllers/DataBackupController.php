<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidDataBackupException;
use App\Services\DataBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataBackupController extends Controller
{
    public function download(DataBackupService $backupService): BinaryFileResponse
    {
        $path = $backupService->createBackup();

        return response()->download(
            $path,
            'kajorec-backup-'.now()->format('Y-m-d-His').'.zip',
            [
                'Cache-Control' => 'no-store, private',
                'Content-Type' => 'application/zip',
            ],
        )->deleteFileAfterSend(true);
    }

    public function import(Request $request, DataBackupService $backupService): RedirectResponse
    {
        $validated = $request->validate([
            'backup_file' => ['required', 'file', 'max:102400'],
        ]);

        try {
            $result = $backupService->mergeBackup($validated['backup_file']);
        } catch (InvalidDataBackupException $exception) {
            return back()->withErrors(['backup_file' => $exception->getMessage()]);
        }

        $added = array_sum($result['added']);

        return back()->with('backup_status', "Merge complete. {$added} new account and record entries were added; existing matching accounts were kept.");
    }
}
