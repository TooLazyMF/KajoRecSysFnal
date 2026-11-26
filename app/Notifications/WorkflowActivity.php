<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowActivity extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly string $activityType,
        private readonly ?int $activityId = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            'database',
            filled($notifiable->email) ? 'mail' : null,
            filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'))
                ? WebPushChannel::class
                : null,
        ]));
    }

    /**
     * @return array{title: string, message: string, url: string, activity_type: string, activity_id: int|null}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'activity_type' => $this->activityType,
            'activity_id' => $this->activityId,
        ];
    }

    /**
     * @return array{title: string, body: string, icon: string, url: string, tag: string}
     */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->message,
            'icon' => '/images/kajorec_logo.png',
            'url' => filled($this->id)
                ? route('notifications.open', $this->id)
                : $this->url,
            'tag' => $this->activityType,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->message)
            ->action('View activity', $this->url);
    }
}
