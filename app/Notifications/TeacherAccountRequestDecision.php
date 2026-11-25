<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherAccountRequestDecision extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $teacherName,
        private readonly bool $approved,
        private readonly ?string $decisionMessage = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->approved ? 'Your KajoRec teacher account is approved' : 'Update on your KajoRec teacher account request')
            ->greeting('Hello '.$this->teacherName);

        if ($this->approved) {
            return $message
                ->line('Your teacher account request has been approved. You can now sign in using the email address and password you registered with.');
        }

        $message->line('Your teacher account request was declined by the guidance counselor.');

        if ($this->decisionMessage !== null) {
            $message->line('Counselor’s note: '.$this->decisionMessage);
        }

        return $message
            ->line('You may submit a corrected request with an updated ID photo.')
            ->action('Submit another request', route('teacher.register'));
    }
}
