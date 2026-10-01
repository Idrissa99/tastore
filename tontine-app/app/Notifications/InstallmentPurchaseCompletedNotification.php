<?php

namespace App\Notifications;

use App\Models\InstallmentPurchase;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class InstallmentPurchaseCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(protected InstallmentPurchase $purchase)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Achat entièrement payé — ' . $this->purchase->product->name)
            ->line("Tu as terminé de payer toutes les tranches pour « {$this->purchase->product->name} ».")
            ->line('Le commerçant va maintenant préparer ta livraison.')
            ->action('Voir mon achat', NotificationLinks::installmentPurchase($this->purchase->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'installment_purchase_id' => $this->purchase->id,
            'product_name' => $this->purchase->product->name,
            'message' => "Tu as fini de payer « {$this->purchase->product->name} ». En attente de livraison.",
            'url' => NotificationLinks::installmentPurchase($this->purchase->id),
        ];
    }
}
