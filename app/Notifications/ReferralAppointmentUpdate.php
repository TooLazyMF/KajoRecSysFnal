<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralAppointmentUpdate extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $event,
        private readonly string $studentName,
        private readonly string $scheduledAt,
        private readonly string $cause,
        private readonly int $referralId,
        private readonly int $appointmentId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array{title: string, message: string, url: string, referral_id: int, appointment_id: int}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message($notifiable),
            'url' => $notifiable->role === 'teacher'
                ? route('teacher.referrals-sent').'#referral-'.$this->referralId
                : route('appointments.index').'#appointment-'.$this->appointmentId,
            'referral_id' => $this->referralId,
            'appointment_id' => $this->appointmentId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hello '.$notifiable->name)
            ->line($this->message($notifiable))
            ->action(
                $notifiable->role === 'teacher' ? 'View referral updates' : 'View appointment',
                $notifiable->role === 'teacher'
                    ? route('teacher.referrals-sent').'#referral-'.$this->referralId
                    : route('appointments.index').'#appointment-'.$this->appointmentId,
            );
    }

    private function title(): string
    {
        return match ($this->event) {
            'proposed' => 'Counseling appointment proposed',
            'confirmed' => 'Counseling appointment confirmed',
            'suggested' => 'Student suggested a different counseling time',
            'completed' => 'Counseling appointment completed',
            'missed' => 'Counseling appointment missed',
            default => 'Referral appointment update',
        };
    }

    private function message(object $notifiable): string
    {
        if ($this->event === 'proposed') {
            return match ($notifiable->role) {
                'student' => "Guidance Counselor assigned an appointment for you on {$this->scheduledAt} about {$this->cause}.",
                'teacher' => "Based on the referral cause for {$this->studentName} ({$this->cause}), the counselor assigned a counseling appointment, so the result for your referral might be delayed.",
                default => "Guidance assigned a counseling appointment for {$this->studentName} based on {$this->cause}.",
            };
        }

        return match ($this->event) {
            'confirmed' => "{$this->studentName} confirmed the counseling appointment for {$this->scheduledAt}. The Class Admission Slip remains on hold until counseling is completed.",
            'suggested' => "{$this->studentName} suggested a different available time for counseling. Guidance needs to review and confirm the new time.",
            'completed' => "Counseling for {$this->studentName} was completed. Guidance may now issue the Class Admission Slip.",
            'missed' => "{$this->scheduledAt} counseling appointment for {$this->studentName} was marked missed. The Class Admission Slip remains on hold until counseling is completed.",
            default => "There is an update to {$this->studentName}'s referral appointment.",
        };
    }
}
