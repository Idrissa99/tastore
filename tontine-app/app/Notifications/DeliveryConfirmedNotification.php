<?php

namespace App\Notifications;

use App\Models\TontineMember;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class DeliveryConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(protected TontineMember $tontineMember)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tontine = $this->tontineMember->tontine;

        return (new MailMessage)
            ->subject('Livraison confirmée — ' . $tontine->name)
            ->line("Le commerçant a confirmé la remise de ton produit pour la tontine « {$tontine->name} ».")
            ->action('Voir la tontine', NotificationLinks::tontine($tontine->id));
    }

    public function toArray(object $notifiable): array
    {
        $tontine = $this->tontineMember->tontine;

        return [
            'tontine_id' => $tontine->id,
            'tontine_name' => $tontine->name,
            'message' => "Ta livraison pour la tontine « {$tontine->name} » a été confirmée.",
            'url' => NotificationLinks::tontine($tontine->id),
        ];
    }
}
