<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class DisputeResolvedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Dispute $dispute)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ton litige a été traité')
            ->line("Ton signalement « {$this->dispute->subject} » a été traité : {$this->dispute->status}.")
            ->line($this->dispute->resolution_note ?? '')
            ->action('Voir la tontine', NotificationLinks::tontine($this->dispute->tontine_id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'dispute_id' => $this->dispute->id,
            'subject' => $this->dispute->subject,
            'status' => $this->dispute->status,
            'message' => "Ton litige « {$this->dispute->subject} » a été {$this->dispute->status}.",
            'url' => NotificationLinks::tontine($this->dispute->tontine_id),
        ];
    }
}
