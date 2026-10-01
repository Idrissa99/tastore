<?php

namespace App\Notifications;

use App\Models\Tontine;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class BecameBeneficiaryNotification extends Notification
{
    use Queueable;

    public function __construct(protected Tontine $tontine)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('C\'est ton tour dans la tontine ' . $this->tontine->name)
            ->line("Tu es maintenant le bénéficiaire du round {$this->tontine->current_round} de la tontine « {$this->tontine->name} ».")
            ->line('Tous les membres vont maintenant cotiser pour toi ce round.')
            ->action('Voir la tontine', NotificationLinks::tontine($this->tontine->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tontine_id' => $this->tontine->id,
            'tontine_name' => $this->tontine->name,
            'round' => $this->tontine->current_round,
            'message' => "Tu es devenu bénéficiaire de la tontine « {$this->tontine->name} » (round {$this->tontine->current_round}).",
            'url' => NotificationLinks::tontine($this->tontine->id),
        ];
    }
}
