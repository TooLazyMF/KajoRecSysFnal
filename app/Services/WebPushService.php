<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushService
{
    /**
     * @param  array<string, mixed>  $subscription
     */
    public function subscribe(int $userId, array $subscription): PushSubscription
    {
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $publicKey = trim((string) data_get($subscription, 'keys.p256dh', ''));
        $authToken = trim((string) data_get($subscription, 'keys.auth', ''));

        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || $publicKey === '' || $authToken === '') {
            throw new RuntimeException('The push subscription is incomplete.');
        }

        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => 'aes128gcm',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): bool
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        if ($report->isSuccess()) {
            return true;
        }

        $status = $report->getResponse()?->getStatusCode();

        if (in_array($status, [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        Log::warning('Web Push delivery failed.', [
            'subscription_id' => $subscription->id,
            'status' => $status,
            'reason' => $report->getReason(),
        ]);

        throw new RuntimeException('The push notification could not be delivered.');
    }
}
