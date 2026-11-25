<?php

use App\Http\Controllers\AccountLogController;
use App\Http\Controllers\SlipSubmissionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::get('settings/profile/client-information', [SlipSubmissionController::class, 'editClientInformation'])
        ->name('profile.client-information.edit');
    Route::get('settings/account-logs', [AccountLogController::class, 'index'])->name('account-logs.index');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::post('settings/security/confirmation', function (Request $request) {
        // Re-entering password confirmation should return users to the security page afterward.
        $request->session()->put('url.intended', route('security.edit'));

        return response()->noContent();
    })->name('security.confirmation.prepare');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
