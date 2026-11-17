<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TeacherAccountCreated extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = URL::temporarySignedRoute(
            'teacher-account.verify',
            now()->addDay(),
            ['user' => $notifiable->getKey()],
        );

        return (new MailMessage)
            ->subject('Your KajoRec teacher account was created')
            ->greeting('Hello '.$notifiable->name)
            ->line('Your KajoRec teacher account was created by the guidance counselor.')
            ->line('Click the button below to verify your email address and activate your account.')
            ->action('Verify and activate account', $verificationUrl)
            ->line('This verification link expires in 24 hours.');
    }
}
