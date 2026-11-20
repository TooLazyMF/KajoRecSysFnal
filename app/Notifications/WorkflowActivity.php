<?php

namespace App\Notifications;

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
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->message)
            ->action('View activity', $this->url);
    }
}
