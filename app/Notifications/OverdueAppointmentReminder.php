<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OverdueAppointmentReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $appointmentId,
        int $counselorId,
        private readonly string $studentName,
        private readonly string $scheduledAt,
    ) {
        $hash = substr(hash('sha256', "overdue-appointment:{$appointmentId}:{$counselorId}"), 0, 32);
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
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string, appointment_id: int}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Overdue appointment needs follow-up',
            'message' => "The appointment with {$this->studentName} scheduled for {$this->scheduledAt} has passed and is still awaiting completion. Mark it as done or missed.",
            'url' => route('appointments.index'),
            'appointment_id' => $this->appointmentId,
        ];
    }
}
