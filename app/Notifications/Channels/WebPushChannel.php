<?php

namespace App\Notifications\Channels;

use App\Services\WebPushService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WebPushChannel
{
    public function __construct(private readonly WebPushService $webPush) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        foreach ($notifiable->pushSubscriptions()->get() as $subscription) {
            try {
                $this->webPush->send($subscription, $notification->toPush($notifiable));
            } catch (\Throwable $exception) {
                Log::warning('Web Push notification failed.', [
                    'user_id' => $notifiable->getKey(),
                    'subscription_id' => $subscription->getKey(),
                    'exception' => $exception,
                ]);
            }
        }
    }
}
