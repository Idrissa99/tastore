<?php

namespace App\Notifications;

use App\Models\Contribution;
use Illuminate\Bus\Queueable;
use App\Services\NotificationLinks;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ContributionVerificationUpdatedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Contribution $contribution)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tontine = $this->contribution->tontineMember->tontine;
        $accepted = $this->contribution->verification_status === 'accepted';

        $message = (new MailMessage)->subject(
            $accepted ? 'Paiement accepté' : 'Paiement refusé'
        );

        if ($accepted) {
            $message->line("Ton paiement pour la tontine « {$tontine->name} » a été vérifié et accepté.");
        } else {
            $message->line("Ton paiement pour la tontine « {$tontine->name} » a été refusé : le code de transfert fourni n'a pas pu être vérifié.");
            $message->line('Tu peux soumettre un nouveau code depuis ton espace.');
        }

        return $message->action('Voir mes cotisations', NotificationLinks::contributions());
    }

    public function toArray(object $notifiable): array
    {
        $tontine = $this->contribution->tontineMember->tontine;
        $accepted = $this->contribution->verification_status === 'accepted';

        return [
            'contribution_id' => $this->contribution->id,
            'verification_status' => $this->contribution->verification_status,
            'message' => $accepted
                ? "Ton paiement pour « {$tontine->name} » a été accepté."
                : "Ton paiement pour « {$tontine->name} » a été refusé. Tu peux resoumettre un code.",
            'url' => NotificationLinks::contributions(),
        ];
    }
}
