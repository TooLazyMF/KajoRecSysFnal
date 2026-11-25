<?php

namespace App\Notifications;

use App\Models\TeacherAccountRequest as TeacherRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TeacherAccountRequestVerification extends Notification
{
    use Queueable;

    public function __construct(private readonly TeacherRequest $teacherRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = URL::temporarySignedRoute(
            'teacher-registration.verify',
            now()->addDay(),
            ['teacherAccountRequest' => $this->teacherRequest->getKey()],
        );

        return (new MailMessage)
            ->subject('Verify your KajoRec teacher account request')
            ->greeting('Hello '.$this->teacherRequest->name)
            ->line('Verify your email address so the guidance counselor can review your teacher account request.')
            ->action('Verify email address', $verificationUrl)
            ->line('This link expires in 24 hours.');
    }
}
