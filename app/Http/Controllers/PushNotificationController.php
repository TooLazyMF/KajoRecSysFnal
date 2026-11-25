<?php

namespace App\Http\Controllers;

use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PushNotificationController extends Controller
{
    public function subscribe(Request $request, WebPushService $webPush): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:500'],
            'keys.auth' => ['required', 'string', 'max:500'],
        ]);

        try {
            $subscription = $webPush->subscribe($request->user()->id, $validated);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'subscription' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Push notifications enabled.',
            'subscription_id' => $subscription->id,
        ], 201);
    }

    public function test(Request $request, WebPushService $webPush): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'url', 'max:2000'],
        ]);

        $sent = 0;

        foreach ($request->user()->pushSubscriptions()->get() as $subscription) {
            if ($webPush->send($subscription, [
                'title' => $validated['title'] ?? 'KAJOREC notification',
                'body' => $validated['body'] ?? 'Web Push notifications are working.',
                'icon' => '/images/kajorec_logo.png',
                'url' => $validated['url'] ?? route('notifications.index'),
            ])) {
                $sent++;
            }
        }

        return response()->json([
            'message' => "Sent {$sent} push notification(s).",
            'sent' => $sent,
        ]);
    }
}
