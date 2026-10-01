<?php

namespace App\Notifications;

use App\Models\InstallmentPurchase;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class InstallmentDeliveryConfirmedNotification extends Notification
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
            ->subject('Livraison confirmée — ' . $this->purchase->product->name)
            ->line("Le commerçant a confirmé la remise de « {$this->purchase->product->name} ».")
            ->action('Voir mon achat', NotificationLinks::installmentPurchase($this->purchase->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'installment_purchase_id' => $this->purchase->id,
            'product_name' => $this->purchase->product->name,
            'message' => "Ta livraison pour « {$this->purchase->product->name} » a été confirmée.",
            'url' => NotificationLinks::installmentPurchase($this->purchase->id),
        ];
    }
}
