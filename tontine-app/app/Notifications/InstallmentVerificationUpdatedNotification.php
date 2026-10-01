<?php

namespace App\Notifications;

use App\Models\Installment;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class InstallmentVerificationUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Installment $installment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = $this->installment->purchase->product;
        $accepted = $this->installment->verification_status === 'accepted';

        $message = (new MailMessage)->subject($accepted ? 'Paiement accepté' : 'Paiement refusé');

        if ($accepted) {
            $message->line("Ton paiement pour la tranche {$this->installment->installment_number} de « {$product->name} » a été vérifié et accepté.");
        } else {
            $message->line("Ton paiement pour la tranche {$this->installment->installment_number} de « {$product->name} » a été refusé.");
            $message->line('Tu peux soumettre un nouveau code depuis ton espace.');
        }

        return $message->action('Voir mes achats', NotificationLinks::installmentPurchase($this->installment->purchase->id));
    }

    public function toArray(object $notifiable): array
    {
        $product = $this->installment->purchase->product;
        $accepted = $this->installment->verification_status === 'accepted';

        return [
            'installment_id' => $this->installment->id,
            'verification_status' => $this->installment->verification_status,
            'message' => $accepted
                ? "Ton paiement pour « {$product->name} » a été accepté."
                : "Ton paiement pour « {$product->name} » a été refusé. Tu peux resoumettre un code.",
            'url' => NotificationLinks::installmentPurchase($this->installment->purchase->id),
        ];
    }
}
