<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(50)
            ->get();

        return view('notifications.index', compact('notifications'));
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $userNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();
        $userNotification->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }
}
