<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $appointmentId,
        int $recipientId,
        private readonly string $otherParticipantName,
        private readonly CarbonInterface $scheduledAt,
    ) {
        $hash = substr(hash(
            'sha256',
            "appointment-reminder:{$appointmentId}:{$recipientId}:{$scheduledAt->toIso8601String()}",
        ), 0, 32);
        $hash[12] = '5';
        $hash[16] = '8';
        $this->id = implode('-', [
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && filled($notifiable->email)
            ? ['database', 'mail']
            : ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string, appointment_id: int}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Appointment reminder',
            'message' => $this->message(),
            'url' => route('appointments.index'),
            'appointment_id' => $this->appointmentId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greeting = $notifiable instanceof User ? 'Hello '.$notifiable->name : 'Hello';

        return (new MailMessage)
            ->subject('Appointment reminder')
            ->greeting($greeting)
            ->line($this->message())
            ->action('View appointment', route('appointments.index'));
    }

    private function message(): string
    {
        return "You have an appointment today with {$this->otherParticipantName} at {$this->scheduledAt->format('g:i A')}.";
    }
}
