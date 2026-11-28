<?php

namespace App\Http\Controllers;

use App\Support\NotificationInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationInbox $inbox): View
    {
        $notifications = $inbox->unreadFor($request->user())
            ->take(50);

        return view('notifications.index', compact('notifications'));
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $userNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();
        $userNotification->markAsRead();

        $target = $userNotification->data['url'] ?? null;
        $path = is_string($target) ? parse_url($target, PHP_URL_PATH) : null;
        $query = is_string($target) ? parse_url($target, PHP_URL_QUERY) : null;
        $fragment = is_string($target) ? parse_url($target, PHP_URL_FRAGMENT) : null;

        if (! is_string($path) || ! Str::startsWith($path, '/') || Str::startsWith($path, '//')) {
            return redirect()->route('notifications.index');
        }

        return redirect()->to($path
            .(is_string($query) && $query !== '' ? '?'.$query : '')
            .(is_string($fragment) && $fragment !== '' ? '#'.$fragment : ''));
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $userNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();
        $userNotification->markAsRead();

        return redirect()->route('notifications.index')->with('status', 'Notification marked as read.');
    }
}
